<?php

namespace App\Services\Telegram\Handlers;

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
        if ($message->getChat()->getType() !== 'private') {
            return;
        }

        // Check for cancel command first
        if ($text === 'إلغاء') {
            $state = $this->getState($userId);
            if ($state) {
                $this->clearState($userId);
                $this->reply($message, 'تم إلغاء العملية.');

                return;
            }
        }

        // Check if user is in a page management state
        $state = $this->getState($userId);

        if ($state) {
            $this->handleState($message, $state);

            return;
        }

        // Check for management commands
        match (true) {
            $text === 'أضف صفحة' => $this->startAddPage($message),
            $text === 'حذف صفحة' => $this->startDeletePage($message),
            $text === 'الفهرس' || $text === 'فهرس الصفحات' => $this->showIndex($message),
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

        // Handle smart search toggle
        if (str_starts_with($data, 'toggle_smart_')) {
            $parts = explode('_', $data);
            $currentValue = end($parts) === '1';
            $newValue = ! $currentValue;

            // Update state
            $state = $this->getState($userId);
            if ($state && $state['step'] === 'awaiting_name') {
                $state['smart_search'] = $newValue;
                $this->setState($userId, $state);

                // Update the button
                $keyboard = $this->buildSmartSearchKeyboard($newValue);

                $this->telegram->editMessageReplyMarkup([
                    'chat_id' => $callback->getMessage()->getChat()->getId(),
                    'message_id' => $callback->getMessage()->getMessageId(),
                    'reply_markup' => $keyboard,
                ]);

                $this->telegram->answerCallbackQuery([
                    'callback_query_id' => $callback->getId(),
                    'text' => $newValue ? 'تم تفعيل البحث الذكي' : 'تم تعطيل البحث الذكي',
                ]);
            }
        }
    }

    protected function startAddPage(Message $message): void
    {
        $userId = $message->getFrom()->getId();

        // Check authorization
        $user = $this->getAuthorizedUser($userId);
        if (! $user) {
            $this->reply($message, "عذراً، ليس لديك صلاحية لإدارة المحتوى.\n\nيجب تسجيل الدخول أولاً بإرسال: تسجيل دخول");

            return;
        }

        $this->setState($userId, [
            'step' => 'awaiting_name',
            'smart_search' => false,
        ]);

        $keyboard = $this->buildSmartSearchKeyboard(false);

        $this->telegram->sendMessage([
            'chat_id' => $message->getChat()->getId(),
            'text' => "أرسل اسم الصفحة:\n\n(أرسل 'إلغاء' للإلغاء)",
            'reply_markup' => $keyboard,
        ]);
    }

    protected function startDeletePage(Message $message): void
    {
        $userId = $message->getFrom()->getId();

        // Check authorization
        $user = $this->getAuthorizedUser($userId);
        if (! $user) {
            $this->reply($message, "عذراً، ليس لديك صلاحية لإدارة المحتوى.\n\nيجب تسجيل الدخول أولاً بإرسال: تسجيل دخول");

            return;
        }

        $this->setState($userId, [
            'step' => 'awaiting_delete_name',
        ]);

        $this->reply($message, "أرسل اسم الصفحة المراد حذفها:\n\n(أرسل 'إلغاء' للإلغاء)");
    }

    protected function showIndex(Message $message): void
    {
        $userId = $message->getFrom()->getId();

        // Check authorization
        $user = $this->getAuthorizedUser($userId);
        if (! $user) {
            $this->reply($message, "عذراً، ليس لديك صلاحية لإدارة المحتوى.\n\nيجب تسجيل الدخول أولاً بإرسال: تسجيل دخول");

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
            $this->reply($message, "عذراً، ليس لديك صلاحية لإدارة المحتوى.\n\nيجب تسجيل الدخول أولاً بإرسال: تسجيل دخول");

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

        switch ($state['step']) {
            case 'awaiting_name':
                $this->handlePageName($message, $text, $state);
                break;

            case 'awaiting_content':
                $this->handlePageContent($message, $text, $state);
                break;

            case 'awaiting_delete_name':
                $this->handleDeletePage($message, $text);
                break;
        }
    }

    protected function handlePageName(Message $message, string $name, array $state): void
    {
        $userId = $message->getFrom()->getId();

        if (empty($name)) {
            $this->reply($message, "اسم الصفحة لا يمكن أن يكون فارغاً.\n\nأرسل اسم الصفحة:");

            return;
        }

        // Check if page exists (for edit mode)
        $existingPage = Page::where('title', $name)->first();

        $this->setState($userId, [
            'step' => 'awaiting_content',
            'name' => $name,
            'smart_search' => $state['smart_search'] ?? false,
            'existing_page_id' => $existingPage?->id,
        ]);

        $mode = $existingPage ? '(تعديل)' : '(جديدة)';
        $this->reply($message, "اسم الصفحة: {$name} {$mode}\n\nأرسل محتوى الصفحة:\n\n(أرسل 'إلغاء' للإلغاء)");
    }

    protected function handlePageContent(Message $message, string $content, array $state): void
    {
        $userId = $message->getFrom()->getId();

        if (empty($content)) {
            $this->reply($message, "محتوى الصفحة لا يمكن أن يكون فارغاً.\n\nأرسل محتوى الصفحة:");

            return;
        }

        // Parse content for buttons and dates
        $parsed = $this->contentParser->parseContent($content);

        // Convert buttons to quick response format
        $buttons = $this->contentParser->convertButtonsToQuickResponseFormat(
            $parsed['buttons'],
            $parsed['row_layout']
        );

        $pageData = [
            'title' => $state['name'],
            'slug' => '/'.Str::slug($state['name']),
            'html_content' => $this->convertToTipTap($parsed['message']),
            'smart_search' => $state['smart_search'],
            'hidden_from_bot' => false,
            'quick_response_auto_extract' => false,
            'quick_response_message' => $parsed['message'],
            'quick_response_buttons' => $buttons,
            'quick_response_send_link' => true,
        ];

        try {
            if ($state['existing_page_id']) {
                // Update existing page
                $page = Page::find($state['existing_page_id']);
                $page->update($pageData);
                $action = 'تعديل';
            } else {
                // Create new page
                // Generate unique slug if needed
                $baseSlug = $pageData['slug'];
                $counter = 1;
                while (Page::where('slug', $pageData['slug'])->exists()) {
                    $pageData['slug'] = $baseSlug.'-'.$counter;
                    $counter++;
                }

                $page = Page::create($pageData);
                $action = 'إضافة';
            }

            $this->clearState($userId);

            $smartText = $page->smart_search ? ' (بحث ذكي)' : '';
            $buttonsText = count($buttons) > 0 ? "\nالأزرار: ".count($buttons) : '';

            $this->reply($message, "✅ تم {$action} الصفحة بنجاح!\n\nالعنوان: {$page->title}{$smartText}{$buttonsText}\nالرابط: ".url($page->slug));
        } catch (\Exception $e) {
            $this->reply($message, "حدث خطأ أثناء حفظ الصفحة: {$e->getMessage()}");
        }
    }

    protected function handleDeletePage(Message $message, string $name): void
    {
        $userId = $message->getFrom()->getId();

        $page = Page::where('title', $name)->first();

        if (! $page) {
            $this->reply($message, "لم يتم العثور على صفحة بهذا الاسم.\n\nأرسل اسم الصفحة كما هو مكتوب في الفهرس:");

            return;
        }

        $title = $page->title;
        $page->delete(); // Soft delete

        $this->clearState($userId);
        $this->reply($message, "✅ تم حذف الصفحة: {$title}");
    }

    protected function getAuthorizedUser(int $telegramId): ?User
    {
        $user = User::findByTelegramId((string) $telegramId);

        if (! $user || ! $user->canManagePagesViaTelegram()) {
            return null;
        }

        return $user;
    }

    protected function buildSmartSearchKeyboard(bool $isEnabled): Keyboard
    {
        $icon = $isEnabled ? '✅' : '❌';
        $callbackData = 'toggle_smart_'.($isEnabled ? '1' : '0');

        return Keyboard::make()
            ->inline()
            ->row([
                Keyboard::inlineButton([
                    'text' => "البحث في كامل الجملة {$icon}",
                    'callback_data' => $callbackData,
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
}
