<?php

namespace App\Services\Telegram\Handlers;

use App\Helpers\ArabicNormalizer;
use App\Models\Page;
use App\Models\User;
use App\Services\Telegram\ContentParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Telegram\Bot\Api;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Message;

class PageManagementHandler extends BaseHandler
{
    private const STATE_KEY_PREFIX = 'telegram_page_mgmt_state_';

    private const STATE_TTL = 600; // 10 minutes

    protected ContentParser $contentParser;

    public function __construct(Api $telegram, ContentParser $contentParser)
    {
        parent::__construct($telegram);
        $this->contentParser = $contentParser;
    }

    public function handle(Message $message): void
    {
        $chatId = $message->getChat()->getId();
        $userId = $message->getFrom()->getId();
        $text = trim($message->getText() ?? '');

        // Only handle private messages for page management
        // if ($message->getChat()->getType() !== 'private') {
        //     return;
        // }

        // Check if user is in a page management state
        $state = $this->getState($userId);

        // Check for cancel command - only cancel if there's an active state
        if ($text === 'إلغاء' || $text === 'الغاء') {
            if ($state) {
                $this->clearState($userId);
                $response = $this->reply($message, 'تم إلغاء العملية.');

                // Track this message and the cancel command
                $state['message_ids'][] = $message->getMessageId();
                $state['message_ids'][] = $response->getMessageId();

                // Delete all messages from the interaction
                $this->deleteAllInteractionMessages($message->getChat()->getId(), $state['message_ids'] ?? []);
            }
            // Don't respond if no active state - let other handlers handle it

            return;
        }

        if ($state) {
            $this->handleState($message, $state);

            return;
        }

        // Check for management commands (with and without hamza)
        match (true) {
            $text === 'أضف صفحة' || $text === 'اضف صفحة' => $this->startAddPage($message),
            $text === 'حذف صفحة' => $this->startDeletePage($message),
            // $text === 'الفهرس' || $text === 'فهرس الصفحات' => $this->showIndex($message),
            $text === 'الصفحات الذكية' => $this->showSmartPages($message),
            default => null,
        };
    }

    /**
     * Handle callback queries (inline button presses).
     */
    public function handleCallback(CallbackQuery $callback): void
    {
        $data = $callback->getData();
        $userId = $callback->getFrom()->getId();

        $state = $this->getState($userId);
        if (! $state || $state['step'] !== 'awaiting_content') {
            return;
        }

        $toggleMap = [
            'toggle_smart' => 'smart_search',
            'toggle_update_content' => 'update_main_content',
            'toggle_send_link' => 'send_link',
            'toggle_prefix' => 'requires_prefix',
        ];

        foreach ($toggleMap as $prefix => $stateKey) {
            if (str_starts_with($data, $prefix.'_')) {
                $parts = explode('_', $data);
                $currentValue = end($parts) === '1';
                $newValue = ! $currentValue;

                $state[$stateKey] = $newValue;
                $this->setState($userId, $state);

                // Update the keyboard with new state
                $keyboard = $this->buildOptionsKeyboard($state);

                $this->telegram->editMessageReplyMarkup([
                    'chat_id' => $callback->getMessage()->getChat()->getId(),
                    'message_id' => $callback->getMessage()->getMessageId(),
                    'reply_markup' => $keyboard,
                ]);

                $labels = [
                    'smart_search' => ['تم تفعيل البحث الذكي', 'تم تعطيل البحث الذكي'],
                    'update_main_content' => ['سيتم تحديث محتوى الصفحة', 'لن يتم تحديث محتوى الصفحة'],
                    'send_link' => ['سيتم إرسال رابط الصفحة', 'لن يتم إرسال رابط الصفحة'],
                    'requires_prefix' => ['يجب كتابة "دليل" قبل اسم الصفحة', 'يمكن البحث بالاسم مباشرة'],
                ];

                $this->telegram->answerCallbackQuery([
                    'callback_query_id' => $callback->getId(),
                    'text' => $newValue ? $labels[$stateKey][0] : $labels[$stateKey][1],
                ]);

                return;
            }
        }
    }

    protected function startAddPage(Message $message): void
    {
        $userId = $message->getFrom()->getId();

        // Check authorization
        $user = $this->getAuthorizedUser($userId);
        if (! $user) {
            $this->replyAndDelete($message, "عذراً، ليس لديك صلاحية لإدارة المحتوى.\n\nيجب تسجيل الدخول أولاً بإرسال: تسجيل دخول");

            return;
        }

        // Initial state - just waiting for name
        $state = [
            'step' => 'awaiting_name',
            'message_ids' => [$message->getMessageId()], // Track the initial command message
        ];

        $response = $this->reply($message, "أرسل اسم الصفحة:\n\n(أرسل 'إلغاء' للإلغاء)");
        $state['message_ids'][] = $response->getMessageId();

        $this->setState($userId, $state);
    }

    protected function startDeletePage(Message $message): void
    {
        $userId = $message->getFrom()->getId();

        // Check authorization
        $user = $this->getAuthorizedUser($userId);
        if (! $user) {
            $this->replyAndDelete($message, "عذراً، ليس لديك صلاحية لإدارة المحتوى.\n\nيجب تسجيل الدخول أولاً بإرسال: تسجيل دخول");

            return;
        }

        $state = [
            'step' => 'awaiting_delete_name',
            'message_ids' => [$message->getMessageId()], // Track the initial command message
        ];

        $response = $this->reply($message, "أرسل اسم الصفحة المراد حذفها:\n\n(أرسل 'إلغاء' للإلغاء)");
        $state['message_ids'][] = $response->getMessageId();

        $this->setState($userId, $state);
    }

    protected function showIndex(Message $message): void
    {
        $userId = $message->getFrom()->getId();

        // Check authorization
        $user = $this->getAuthorizedUser($userId);
        if (! $user) {
            $this->replyAndDelete($message, "عذراً، ليس لديك صلاحية لإدارة المحتوى.\n\nيجب تسجيل الدخول أولاً بإرسال: تسجيل دخول");

            return;
        }

        $pages = Page::visibleInBot()
            ->rootLevel()
            ->orderBy('order')
            ->get();

        if ($pages->isEmpty()) {
            $this->reply($message, 'لا توجد صفحات حالياً.');

            return;
        }

        $index = $this->buildPageTree($pages);

        $this->reply($message, "📚 فهرس الصفحات:\n\n".$index);
    }

    protected function showSmartPages(Message $message): void
    {
        $userId = $message->getFrom()->getId();

        // Check authorization
        $user = $this->getAuthorizedUser($userId);
        if (! $user) {
            $this->replyAndDelete($message, "عذراً، ليس لديك صلاحية لإدارة المحتوى.\n\nيجب تسجيل الدخول أولاً بإرسال: تسجيل دخول");

            return;
        }

        $pages = Page::visibleInBot()
            ->smartSearch()
            ->orderBy('order')
            ->get();

        if ($pages->isEmpty()) {
            $this->reply($message, 'لا توجد صفحات ذكية حالياً.');

            return;
        }

        $list = $pages->map(function ($page) {
            $icon = $page->icon ? $page->icon.' ' : '';

            return "• {$icon}{$page->title}";
        })->join("\n");

        $this->reply($message, "🔍 الصفحات الذكية:\n\n".$list);
    }

    protected function handleState(Message $message, array $state): void
    {
        $userId = $message->getFrom()->getId();
        $text = trim($message->getText() ?? '');

        // Get caption and entities from photo/document if no text
        $caption = $message->getCaption();
        $photo = $message->getPhoto();
        $document = $message->getDocument();

        // Get entities for formatting preservation
        $entities = $message->get('entities');
        $captionEntities = $message->get('caption_entities');

        switch ($state['step']) {
            case 'awaiting_name':
                $this->handlePageName($message, $text, $state);
                break;

            case 'awaiting_content':
                $this->handlePageContent($message, $text, $caption, $photo, $document, $entities, $captionEntities, $state);
                break;

            case 'awaiting_delete_name':
                $this->handleDeletePage($message, $text, $state);
                break;
        }
    }

    protected function handlePageName(Message $message, string $name, array $state): void
    {
        $userId = $message->getFrom()->getId();

        // Track user message
        $state['message_ids'][] = $message->getMessageId();

        if (empty($name)) {
            $response = $this->reply($message, "اسم الصفحة لا يمكن أن يكون فارغاً.\n\nأرسل اسم الصفحة:");
            $state['message_ids'][] = $response->getMessageId();
            $this->setState($userId, $state);

            return;
        }

        // Check if page exists using normalized comparison (handles همزة and ال variations)
        $normalizedName = ArabicNormalizer::normalize($name);
        $normalizedNameWithoutAl = ArabicNormalizer::normalizeWithoutDefiniteArticle($name);

        $existingPage = Page::all()->first(function ($page) use ($normalizedName, $normalizedNameWithoutAl) {
            $normalizedTitle = ArabicNormalizer::normalize($page->title);
            $normalizedTitleWithoutAl = ArabicNormalizer::normalizeWithoutDefiniteArticle($page->title);

            // Match with full normalization or without ال
            return $normalizedTitle === $normalizedName ||
                   $normalizedTitleWithoutAl === $normalizedNameWithoutAl;
        });

        // Set toggle states based on existing page or defaults for new pages
        if ($existingPage) {
            // Editing existing page - load its current settings
            $state['smart_search'] = $existingPage->smart_search ?? false;
            $state['send_link'] = $existingPage->quick_response_send_link ?? false;
            $state['requires_prefix'] = $existingPage->requires_prefix ?? true;
            $state['update_main_content'] = false; // Always default to false for edits
        } else {
            // New page from bot - default requires_prefix to false
            $state['smart_search'] = false;
            $state['send_link'] = false;
            $state['requires_prefix'] = false;
            $state['update_main_content'] = false;
        }

        $state['step'] = 'awaiting_content';
        $state['name'] = $name;
        $state['existing_page_id'] = $existingPage?->id;

        $mode = $existingPage ? '(تعديل)' : '(جديدة)';
        $keyboard = $this->buildOptionsKeyboard($state);

        $response = $this->telegram->sendMessage([
            'chat_id' => $message->getChat()->getId(),
            'text' => "اسم الصفحة: {$name} {$mode}\n\nأرسل محتوى الصفحة:\n\n(أرسل 'إلغاء' للإلغاء)",
            'reply_markup' => $keyboard,
        ]);
        $state['message_ids'][] = $response->getMessageId();

        $this->setState($userId, $state);
    }

    protected function handlePageContent(Message $message, string $content, ?string $caption, $photo, $document, $entities, $captionEntities, array $state): void
    {
        $userId = $message->getFrom()->getId();

        // Track user message
        $state['message_ids'][] = $message->getMessageId();

        // Use caption if photo/document sent, otherwise use text content
        $textContent = $content;
        $activeEntities = $entities;
        if (empty($textContent) && $caption) {
            $textContent = $caption;
            $activeEntities = $captionEntities;
        }

        // Handle attachment if sent
        $attachments = [];
        if ($photo) {
            // Photo is a Collection of PhotoSize objects - get the largest one
            $photoSizes = collect($photo);
            if ($photoSizes->isNotEmpty()) {
                // Get the last (largest) photo
                $largestPhoto = $photoSizes->last();
                $fileId = is_array($largestPhoto) ? ($largestPhoto['file_id'] ?? null) : $largestPhoto->get('file_id');
                if ($fileId) {
                    $savedPath = $this->downloadAndSaveFile($fileId, 'photo');
                    if ($savedPath) {
                        $attachments[] = $savedPath;
                    }
                }
            }
        } elseif ($document) {
            $fileId = is_array($document) ? ($document['file_id'] ?? null) : $document->get('file_id');
            $fileName = is_array($document) ? ($document['file_name'] ?? 'document') : ($document->get('file_name') ?? 'document');
            if ($fileId) {
                $savedPath = $this->downloadAndSaveFile($fileId, 'document', $fileName);
                if ($savedPath) {
                    $attachments[] = $savedPath;
                }
            }
        }

        if (empty($textContent) && empty($attachments)) {
            $response = $this->reply($message, "محتوى الصفحة لا يمكن أن يكون فارغاً.\n\nأرسل محتوى الصفحة (نص أو صورة مع تعليق):");
            $state['message_ids'][] = $response->getMessageId();
            $this->setState($userId, $state);

            return;
        }

        // Parse buttons from RAW text first (before formatting) - URLs must be plain
        $parsed = $this->contentParser->parseContentWithoutDates($textContent ?? '');

        // Convert buttons to quick response format
        $buttons = $this->contentParser->convertButtonsToQuickResponseFormat(
            $parsed['buttons'],
            $parsed['row_layout']
        );

        // Now apply HTML formatting from entities to the message (after buttons are extracted)
        $formattedMessage = $this->applyEntitiesFormatting($parsed['message'], $activeEntities);

        // Get toggle states
        $smartSearch = $state['smart_search'] ?? false;
        $updateMainContent = $state['update_main_content'] ?? false;
        $sendLink = $state['send_link'] ?? false;
        $requiresPrefix = $state['requires_prefix'] ?? true;

        try {
            if ($state['existing_page_id']) {
                // Update existing page
                $page = Page::find($state['existing_page_id']);

                $updateData = [
                    'smart_search' => $smartSearch,
                    'requires_prefix' => $requiresPrefix,
                    'hidden_from_bot' => false,
                    'quick_response_auto_extract' => false,
                    'quick_response_message' => $formattedMessage,
                    'quick_response_buttons' => $buttons,
                    'quick_response_send_link' => $sendLink,
                ];

                // Only update main content if toggle is ON
                if ($updateMainContent) {
                    $updateData['html_content'] = $this->convertToTipTap($parsed['message']);
                    // Don't change hidden state - leave as is
                }

                // Only update attachments if new ones were provided
                if (! empty($attachments)) {
                    $updateData['quick_response_attachments'] = $attachments;
                }

                $page->update($updateData);
                $action = 'تعديل';
            } else {
                // Create new page
                $slug = '/'.Str::slug($state['name']);

                // Generate unique slug if needed
                $baseSlug = $slug;
                $counter = 1;
                while (Page::where('slug', $slug)->exists()) {
                    $slug = $baseSlug.'-'.$counter;
                    $counter++;
                }

                // New pages: hidden from website unless update_main_content is ON
                $hiddenFromWebsite = ! $updateMainContent;

                $pageData = [
                    'title' => $state['name'],
                    'slug' => $slug,
                    'html_content' => $this->convertToTipTap($parsed['message']),
                    'hidden' => $hiddenFromWebsite,
                    'hidden_from_bot' => false,
                    'smart_search' => $smartSearch,
                    'requires_prefix' => $requiresPrefix,
                    'quick_response_auto_extract' => false,
                    'quick_response_message' => $formattedMessage,
                    'quick_response_buttons' => $buttons,
                    'quick_response_attachments' => $attachments,
                    'quick_response_send_link' => $sendLink,
                ];

                $page = Page::create($pageData);
                $action = 'إضافة';
            }

            $this->clearState($userId);

            $smartText = $page->smart_search ? ' (بحث ذكي)' : '';
            $prefixText = $page->requires_prefix ? '' : ' (بدون دليل)';
            $buttonsText = count($buttons) > 0 ? "\nالأزرار: ".count($buttons) : '';
            $attachmentsText = ! empty($attachments) ? "\nالمرفقات: ".count($attachments) : '';
            $contentUpdated = $updateMainContent ? "\n(تم تحديث محتوى الصفحة)" : '';

            $response = $this->reply($message, "✅ تم {$action} الصفحة بنجاح!\n\nالعنوان: {$page->title}{$smartText}{$prefixText}{$buttonsText}{$attachmentsText}{$contentUpdated}");
            $state['message_ids'][] = $response->getMessageId();

            // Delete all messages from the interaction
            $this->deleteAllInteractionMessages($message->getChat()->getId(), $state['message_ids']);
        } catch (\Exception $e) {
            $response = $this->reply($message, "حدث خطأ أثناء حفظ الصفحة: {$e->getMessage()}");
            $state['message_ids'][] = $response->getMessageId();

            // Delete all messages even on error
            $this->deleteAllInteractionMessages($message->getChat()->getId(), $state['message_ids']);
        }
    }

    /**
     * Download a file from Telegram and save it locally.
     */
    protected function downloadAndSaveFile(string $fileId, string $type, ?string $fileName = null): ?string
    {
        try {
            // Get file info from Telegram
            $file = $this->telegram->getFile(['file_id' => $fileId]);
            $filePath = $file->getFilePath();

            if (! $filePath) {
                \Illuminate\Support\Facades\Log::error('Telegram file download: No file path returned', ['file_id' => $fileId]);

                return null;
            }

            // Determine extension and filename
            $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
            if ($type === 'document' && $fileName) {
                $savedFileName = $fileName;
            } else {
                $savedFileName = 'telegram_'.time().'_'.uniqid().'.'.$extension;
            }

            // Create directory if needed
            $storageDir = storage_path('app/public/quick-responses');
            if (! is_dir($storageDir)) {
                mkdir($storageDir, 0755, true);
            }

            // Use SDK's downloadFile method
            $localPath = $storageDir.'/'.$savedFileName;
            $this->telegram->downloadFile($file, $localPath);

            // Verify file was saved
            if (! file_exists($localPath)) {
                \Illuminate\Support\Facades\Log::error('Telegram file download: File not saved', ['path' => $localPath]);

                return null;
            }

            return 'quick-responses/'.$savedFileName;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Telegram file download error', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Apply Telegram entities formatting to convert to HTML.
     * Note: Only applies to entities that fit within the text bounds.
     * Skips 'url' type since plain URLs auto-link in Telegram HTML mode.
     */
    protected function applyEntitiesFormatting(?string $text, $entities): string
    {
        if (empty($text) || empty($entities)) {
            return $text ?? '';
        }

        // Convert entities to array if it's a collection
        $entitiesArray = $entities instanceof \Illuminate\Support\Collection ? $entities->toArray() : (array) $entities;

        if (empty($entitiesArray)) {
            return $text;
        }

        $textLength = mb_strlen($text, 'UTF-8');

        // Filter valid entities that fit within text bounds and are supported
        $validEntities = array_filter($entitiesArray, function ($entity) use ($textLength) {
            $type = is_array($entity) ? ($entity['type'] ?? '') : ($entity->type ?? '');
            $offset = is_array($entity) ? ($entity['offset'] ?? 0) : ($entity->offset ?? 0);
            $length = is_array($entity) ? ($entity['length'] ?? 0) : ($entity->length ?? 0);

            // Skip 'url' type - plain URLs auto-link in Telegram HTML mode
            // Skip entities that would be out of bounds (happens after button removal)
            $supportedTypes = ['bold', 'italic', 'underline', 'strikethrough', 'code', 'pre', 'text_link'];

            return in_array($type, $supportedTypes) && $offset >= 0 && ($offset + $length) <= $textLength;
        });

        if (empty($validEntities)) {
            return $text;
        }

        // Sort entities by offset in reverse order to apply from end to start
        // This prevents offset shifting when inserting tags
        usort($validEntities, function ($a, $b) {
            $offsetA = is_array($a) ? ($a['offset'] ?? 0) : ($a->offset ?? 0);
            $offsetB = is_array($b) ? ($b['offset'] ?? 0) : ($b->offset ?? 0);

            return $offsetB - $offsetA;
        });

        $result = $text;

        foreach ($validEntities as $entity) {
            $type = is_array($entity) ? ($entity['type'] ?? '') : ($entity->type ?? '');
            $offset = is_array($entity) ? ($entity['offset'] ?? 0) : ($entity->offset ?? 0);
            $length = is_array($entity) ? ($entity['length'] ?? 0) : ($entity->length ?? 0);
            $url = is_array($entity) ? ($entity['url'] ?? null) : ($entity->url ?? null);

            // Extract the text portion
            $beforeText = mb_substr($result, 0, $offset, 'UTF-8');
            $entityText = mb_substr($result, $offset, $length, 'UTF-8');
            $afterText = mb_substr($result, $offset + $length, null, 'UTF-8');

            // Apply formatting based on entity type
            $formattedText = match ($type) {
                'bold' => "<b>{$entityText}</b>",
                'italic' => "<i>{$entityText}</i>",
                'underline' => "<u>{$entityText}</u>",
                'strikethrough' => "<s>{$entityText}</s>",
                'code' => "<code>{$entityText}</code>",
                'pre' => "<pre>{$entityText}</pre>",
                'text_link' => $url ? "<a href=\"{$url}\">{$entityText}</a>" : $entityText,
                default => $entityText,
            };

            $result = $beforeText.$formattedText.$afterText;
        }

        return $result;
    }

    protected function handleDeletePage(Message $message, string $name, array $state): void
    {
        $userId = $message->getFrom()->getId();

        // Track user message
        $state['message_ids'][] = $message->getMessageId();

        // Find page using normalized comparison (handles همزة and ال variations)
        $normalizedName = ArabicNormalizer::normalize($name);
        $normalizedNameWithoutAl = ArabicNormalizer::normalizeWithoutDefiniteArticle($name);

        $page = Page::all()->first(function ($page) use ($normalizedName, $normalizedNameWithoutAl) {
            $normalizedTitle = ArabicNormalizer::normalize($page->title);
            $normalizedTitleWithoutAl = ArabicNormalizer::normalizeWithoutDefiniteArticle($page->title);

            // Match with full normalization or without ال
            return $normalizedTitle === $normalizedName ||
                   $normalizedTitleWithoutAl === $normalizedNameWithoutAl;
        });

        if (! $page) {
            $response = $this->reply($message, "لم يتم العثور على صفحة بهذا الاسم.\n\nأرسل اسم الصفحة:");
            $state['message_ids'][] = $response->getMessageId();
            $this->setState($userId, $state);

            return;
        }

        $title = $page->title;
        $page->delete(); // Soft delete

        $this->clearState($userId);

        $response = $this->reply($message, "✅ تم حذف الصفحة: {$title}");
        $state['message_ids'][] = $response->getMessageId();

        // Delete all messages from the interaction
        $this->deleteAllInteractionMessages($message->getChat()->getId(), $state['message_ids']);
    }

    protected function getAuthorizedUser(int $telegramId): ?User
    {
        $user = User::findByTelegramId((string) $telegramId);

        if (! $user || ! $user->canManagePagesViaTelegram()) {
            return null;
        }

        return $user;
    }

    protected function buildOptionsKeyboard(array $state): Keyboard
    {
        $smartSearch = $state['smart_search'] ?? false;
        $updateContent = $state['update_main_content'] ?? false;
        $sendLink = $state['send_link'] ?? false;
        $requiresPrefix = $state['requires_prefix'] ?? true;

        $smartIcon = $smartSearch ? '✅' : '❌';
        $updateIcon = $updateContent ? '✅' : '❌';
        $linkIcon = $sendLink ? '✅' : '❌';
        $prefixIcon = $requiresPrefix ? '✅' : '❌';

        return Keyboard::make()
            ->inline()
            ->row([
                Keyboard::inlineButton([
                    'text' => "البحث في كامل الجملة {$smartIcon}",
                    'callback_data' => 'toggle_smart_'.($smartSearch ? '1' : '0'),
                ]),
            ])
            ->row([
                Keyboard::inlineButton([
                    'text' => "يتطلب كلمة \"دليل\" {$prefixIcon}",
                    'callback_data' => 'toggle_prefix_'.($requiresPrefix ? '1' : '0'),
                ]),
            ])
            ->row([
                Keyboard::inlineButton([
                    'text' => "تحديث محتوى الصفحة {$updateIcon}",
                    'callback_data' => 'toggle_update_content_'.($updateContent ? '1' : '0'),
                ]),
            ])
            ->row([
                Keyboard::inlineButton([
                    'text' => "إرسال رابط الصفحة مع الرد {$linkIcon}",
                    'callback_data' => 'toggle_send_link_'.($sendLink ? '1' : '0'),
                ]),
            ]);
    }

    protected function buildPageTree($pages, int $level = 0): string
    {
        $result = [];
        $indent = str_repeat('  ', $level);

        foreach ($pages as $page) {
            $icon = $page->icon ? $page->icon.' ' : '';
            $smartIcon = $page->smart_search ? ' 🔍' : '';
            $result[] = "{$indent}• {$icon}{$page->title}{$smartIcon}";

            // Load children
            $children = $page->children()->visibleInBot()->orderBy('order')->get();
            if ($children->isNotEmpty()) {
                $result[] = $this->buildPageTree($children, $level + 1);
            }
        }

        return implode("\n", $result);
    }

    protected function convertToTipTap(string $text): array
    {
        // Convert plain text to TipTap JSON format
        $lines = explode("\n", $text);
        $content = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $line,
                    ],
                ],
            ];
        }

        return [
            'type' => 'doc',
            'content' => $content,
        ];
    }

    protected function getState(int $userId): ?array
    {
        return Cache::get(self::STATE_KEY_PREFIX.$userId);
    }

    protected function setState(int $userId, array $state): void
    {
        Cache::put(self::STATE_KEY_PREFIX.$userId, $state, self::STATE_TTL);
    }

    protected function clearState(int $userId): void
    {
        Cache::forget(self::STATE_KEY_PREFIX.$userId);
    }

    /**
     * Delete all messages from an interaction after a delay.
     */
    protected function deleteAllInteractionMessages(int $chatId, array $messageIds, int $delaySeconds = 5): void
    {
        sleep($delaySeconds);

        foreach ($messageIds as $messageId) {
            try {
                $this->telegram->deleteMessage([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                ]);
            } catch (\Exception $e) {
                // Silently fail - message might already be deleted or bot lacks permissions
            }
        }
    }
}
