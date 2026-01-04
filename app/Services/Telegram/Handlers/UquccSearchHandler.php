<?php

namespace App\Services\Telegram\Handlers;

use App\Models\Page;
use App\Services\OgImageService;
use App\Services\QuickResponseService;
use App\Services\Telegram\ContentParser;
use App\Services\Telegram\Traits\SearchesPages;
use App\Services\TipTapContentExtractor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Message;

class UquccSearchHandler extends BaseHandler
{
    use SearchesPages;

    protected array $exceptionWords = [
        // 'لابتوب'
    ];

    protected ContentParser $contentParser;

    public function __construct(
        \Telegram\Bot\Api $telegram,
        protected QuickResponseService $quickResponses,
        protected TipTapContentExtractor $contentExtractor,
        protected OgImageService $ogImageService
    ) {
        parent::__construct($telegram);
        $this->contentParser = app(ContentParser::class);
    }

    public function handle(Message $message): void
    {
        $text = $message->getText();
        // Ensure getText() returns a string (handle edge cases where it might be an array)
        $content = is_string($text) ? trim($text) : '';

        if (empty($content)) {
            return;
        }

        // Check if user is in an active state (page management, login, etc.)
        // Don't respond to queries if they're in the middle of another operation
        $userId = $message->getFrom()->getId();
        if ($this->hasActiveState($userId)) {
            return;
        }

        // Check if it matches "دليل <query>" or is one of the exception words
        $isCommand = false;
        $query = null;

        if (preg_match('/^دليل\s+(.+)$/u', $content, $matches)) {
            $isCommand = true;
            $query = $matches[1];
        } elseif (in_array($content, $this->exceptionWords)) {
            $isCommand = true;
            $query = $content;
        }

        if ($isCommand) {
            $this->searchAndRespond($message, $query);

            return;
        }

        // Check for pages that don't require prefix - direct title match
        $directMatch = $this->checkDirectTitleMatch($message, $content);
        if ($directMatch) {
            return;
        }

        // Check for smart search pages - ANY message that contains a smart page title
        $this->checkSmartSearch($message, $content);
    }

    /**
     * Check if user has an active state (login, page management, etc.).
     */
    protected function hasActiveState(int $userId): bool
    {
        // Check for page management state
        $pageMgmtPrefix = config('app-cache.keys.telegram_page_mgmt_state', 'telegram_page_mgmt_state_');
        if (Cache::has($pageMgmtPrefix.$userId)) {
            return true;
        }

        // Check for login state
        $loginPrefix = config('app-cache.keys.telegram_login_state', 'telegram_login_state_');
        if (Cache::has($loginPrefix.$userId)) {
            return true;
        }

        return false;
    }

    /**
     * Check if the message matches a page title that doesn't require prefix.
     * Uses Arabic normalization to handle همزة and ال variations.
     */
    protected function checkDirectTitleMatch(Message $message, string $content): bool
    {
        $page = $this->findPageByDirectTitleMatch($content);

        if ($page) {
            $this->sendPageResult($message, $page);

            return true;
        }

        return false;
    }

    /**
     * Check if the message contains a smart search page title.
     * Uses Arabic normalization to handle همزة and ال variations.
     */
    protected function checkSmartSearch(Message $message, string $content): void
    {
        $page = $this->findPageBySmartSearch($content);

        if ($page) {
            $this->sendPageResult($message, $page);
        }
    }

    protected function searchAndRespond(Message $message, string $query): void
    {
        $this->trackCommand($message, 'دليل');

        $page = $this->searchPage($query);

        if (! $page) {
            $sentMessage = $this->telegram->sendMessage([
                'chat_id' => $message->getChat()->getId(),
                'text' => 'الصفحة غير موجودة',
                'reply_to_message_id' => $message->getMessageId(),
            ]);

            // Delete both the user message and bot response after 5 seconds
            $this->deleteMessagesAfterDelay($message, $sentMessage);

            return;
        }

        $this->sendPageResult($message, $page);
    }

    protected function sendPageResult(Message $message, Page $page): void
    {
        // Get the resolved content (auto-extracted or custom)
        $resolvedContent = $this->resolveQuickResponseContent($page);
        $replyMarkup = $this->buildReplyMarkup($page, $resolvedContent['buttons']);

        // Check if there are attachments
        $attachments = collect($resolvedContent['attachments'])
            ->filter()
            ->values();

        if ($attachments->isNotEmpty()) {
            // Send attachments with text as caption (shorter limit)
            $captionContent = $this->buildTextContent($page, $resolvedContent, isCaption: true);
            $this->sendQuickResponseAttachments($message, $page, $captionContent, $replyMarkup, $attachments);
        } elseif ($page->quick_response_send_screenshot && ! $page->hidden) {
            // Send screenshot with custom content as caption (only if page is not hidden from website)
            $captionContent = $this->buildTextContent($page, $resolvedContent, isCaption: true);
            $this->sendScreenshotWithText($message, $page, $captionContent, $replyMarkup);
        } elseif ($resolvedContent['message'] || $replyMarkup) {
            // Send text message with optional buttons (full message limit)
            $textContent = $this->buildTextContent($page, $resolvedContent, isCaption: false);
            $params = [
                'chat_id' => $message->getChat()->getId(),
                'text' => $textContent,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $message->getMessageId(),
            ];

            if ($replyMarkup) {
                $params['reply_markup'] = $replyMarkup;
            }

            $this->telegram->sendMessage($params);
        } else {
            // Fallback: send screenshot with text as caption (shorter limit)
            $captionContent = $this->buildTextContent($page, $resolvedContent, isCaption: true);
            $this->sendScreenshotWithText($message, $page, $captionContent, $replyMarkup);
        }
    }

    /**
     * Resolve the quick response content based on settings.
     *
     * Logic:
     * - If auto_extract_X is ON: use extracted content for field X
     * - If auto_extract_X is OFF: use custom values from DB for field X
     *
     * @return array{message: string|null, buttons: array, attachments: array}
     */
    protected function resolveQuickResponseContent(Page $page): array
    {
        // Get auto-extracted content if any field needs it
        $extracted = null;
        if ($page->quick_response_auto_extract_message
            || $page->quick_response_auto_extract_buttons
            || $page->quick_response_auto_extract_attachments) {
            $extracted = $this->contentExtractor->getExtractedContent($page);
        }

        return [
            'message' => $page->quick_response_auto_extract_message
                ? ($extracted['message'] ?? null)
                : $page->quick_response_message,
            'buttons' => $page->quick_response_auto_extract_buttons
                ? ($extracted['buttons'] ?? [])
                : ($page->quick_response_buttons ?? []),
            'attachments' => $page->quick_response_auto_extract_attachments
                ? ($extracted['attachments'] ?? [])
                : ($page->quick_response_attachments ?? []),
        ];
    }

    // Content limits for auto-extracted content (shorter to keep messages readable)
    protected const AUTO_MESSAGE_LIMIT = 2500;

    protected const AUTO_CAPTION_LIMIT = 1000;

    // Content limits for user-customized content (close to Telegram max)
    protected const CUSTOM_MESSAGE_LIMIT = 4000;

    protected const CUSTOM_CAPTION_LIMIT = 1000;

    /**
     * Build the text content for the message.
     *
     * @param  Page  $page  The page being sent
     * @param  array{message: string|null, buttons: array, attachments: array}  $resolvedContent  The resolved content
     * @param  bool  $isCaption  Whether this is for a caption (shorter limit)
     */
    protected function buildTextContent(Page $page, array $resolvedContent, bool $isCaption = false): string
    {
        $pageUrl = url($page->slug);

        // Use different limits based on whether content is auto-extracted or user-customized
        // User-customized = auto_extract_message is OFF
        $isCustomContent = ! $page->quick_response_auto_extract_message;

        if ($isCaption) {
            $limit = $isCustomContent ? self::CUSTOM_CAPTION_LIMIT : self::AUTO_CAPTION_LIMIT;
        } else {
            $limit = $isCustomContent ? self::CUSTOM_MESSAGE_LIMIT : self::AUTO_MESSAGE_LIMIT;
        }

        // Ensure title is a string and escape HTML entities
        $title = is_string($page->title) ? $page->title : (string) $page->title;
        $escapedTitle = $this->escapeHtml($title);
        $lines = ["<b>{$escapedTitle}</b>"];

        // Add message content if available
        if ($resolvedContent['message']) {
            $messageText = is_string($resolvedContent['message'])
                ? $resolvedContent['message']
                : (string) $resolvedContent['message'];

            // Process date placeholders at send time (calculates countdown dynamically)
            $messageText = $this->contentParser->processDates($messageText);

            // Strip any wrapping HTML document tags if present (from RichEditor)
            $messageText = $this->cleanHtmlContent($messageText);

            $lines[] = $messageText;
        }

        // Build links in HTML format
        $escapedUrl = $this->escapeHtml($pageUrl);
        $readMoreLink = "📖 <a href=\"{$escapedUrl}\">اقرأ المزيد على الموقع</a>";
        $regularLink = $readMoreLink;
        // $regularLink = "🔗 اقرأ المزيد على الموقع: <a href=\"{$escapedUrl}\">{$escapedUrl}</a>";

        // Check if we need to truncate
        $resultWithoutLink = implode("\n\n", array_filter($lines));
        $needsTruncation = mb_strlen(strip_tags($resultWithoutLink)) > $limit;

        if ($needsTruncation) {
            // Reserve space for the "read more" link
            $readMoreLength = mb_strlen("\n\n...\n\n".strip_tags($readMoreLink));
            $truncated = $this->truncateHtmlSafe($resultWithoutLink, $limit - $readMoreLength - 50);

            return $truncated."\n\n...\n\n".$readMoreLink;
        }

        // No truncation needed - add regular link if enabled
        if ($page->quick_response_send_link) {
            $lines[] = $regularLink;
        }

        return implode("\n\n", array_filter($lines));
    }

    /**
     * Escape HTML entities for safe display in Telegram.
     */
    protected function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Clean HTML content from RichEditor - remove wrapper tags and normalize.
     */
    protected function cleanHtmlContent(string $html): string
    {
        // Remove any DOCTYPE, html, head, body tags
        $html = preg_replace('/<(!DOCTYPE|html|head|body)[^>]*>/i', '', $html);
        $html = preg_replace('/<\/(html|head|body)>/i', '', $html);

        // Convert paragraph tags to double newlines (use word boundary to avoid matching <pre>)
        $html = preg_replace('/<p\b[^>]*>/i', '', $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);

        // Convert br tags to newlines
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

        // Keep only Telegram-supported HTML tags
        $html = strip_tags($html, '<b><strong><i><em><u><s><strike><del><code><pre><a>');

        // Normalize strong to b, em to i for consistency
        $html = preg_replace('/<strong>/i', '<b>', $html);
        $html = preg_replace('/<\/strong>/i', '</b>', $html);
        $html = preg_replace('/<em>/i', '<i>', $html);
        $html = preg_replace('/<\/em>/i', '</i>', $html);
        $html = preg_replace('/<strike>/i', '<s>', $html);
        $html = preg_replace('/<\/strike>/i', '</s>', $html);
        $html = preg_replace('/<del>/i', '<s>', $html);
        $html = preg_replace('/<\/del>/i', '</s>', $html);

        // Trim and normalize whitespace
        $html = trim($html);
        $html = preg_replace('/\n{3,}/', "\n\n", $html);

        return $html;
    }

    /**
     * Truncate HTML text safely without breaking tags.
     */
    protected function truncateHtmlSafe(string $html, int $maxLength): string
    {
        $plainText = strip_tags($html);
        if (mb_strlen($plainText) <= $maxLength) {
            return $html;
        }

        // Simple truncation - find a safe break point
        $truncated = mb_substr($plainText, 0, $maxLength);

        // Try to break at last paragraph
        $lastParagraph = mb_strrpos($truncated, "\n\n");
        if ($lastParagraph !== false && $lastParagraph > $maxLength * 0.3) {
            $truncated = mb_substr($truncated, 0, $lastParagraph);
        }

        return $this->escapeHtml($truncated);
    }

    /**
     * Build the inline keyboard markup for buttons.
     *
     * @param  Page  $page  The page (for context)
     * @param  array  $buttonsData  The resolved buttons array
     */
    protected function buildReplyMarkup(Page $page, array $buttonsData): ?string
    {
        if (empty($buttonsData)) {
            return null;
        }

        $buttons = collect($buttonsData)
            ->filter(function ($btn) {
                if (! is_array($btn)) {
                    return false;
                }
                $text = $btn['text'] ?? null;
                $url = $btn['url'] ?? null;

                return filled($text) && filled($url) && is_string($text) && is_string($url);
            })
            ->map(function ($btn) {
                return [
                    'text' => (string) $btn['text'],
                    'url' => (string) $btn['url'],
                    'size' => $btn['size'] ?? 'full', // Default to full width
                ];
            })
            ->values()
            ->all();

        if (empty($buttons)) {
            return null;
        }

        // Group buttons by size to create rows
        $keyboard = $this->groupButtonsBySize($buttons);

        if (empty($keyboard)) {
            return null;
        }

        // The SDK expects reply_markup to be a JSON-encoded string, not an array
        return json_encode([
            'inline_keyboard' => $keyboard,
        ]);
    }

    protected function sendScreenshotWithText(Message $message, Page $page, string $caption, ?string $replyMarkup = null): void
    {
        $chatId = $message->getChat()->getId();
        $loadingMessage = null;

        // Check if screenshot needs to be generated (not cached)
        if (! $this->ogImageService->hasPageScreenshot($page, OgImageService::TYPE_BOT)) {
            // Send loading message
            $loadingMessage = $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '⏳ جاري تجهيز الصورة...',
                'reply_to_message_id' => $message->getMessageId(),
            ]);
        }

        try {
            // Get cached or generate new screenshot using OgImageService with bot dimensions
            $screenshotPath = $this->ogImageService->generatePageScreenshot($page, OgImageService::TYPE_BOT);

            $params = [
                'chat_id' => $chatId,
                'photo' => InputFile::create($screenshotPath, 'screenshot.webp'),
                'caption' => $caption,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $message->getMessageId(),
            ];

            if ($replyMarkup) {
                $params['reply_markup'] = $replyMarkup;
            }

            $this->telegram->sendPhoto($params);
        } finally {
            // Delete loading message if it was sent
            if ($loadingMessage) {
                try {
                    $this->telegram->deleteMessage([
                        'chat_id' => $chatId,
                        'message_id' => $loadingMessage->getMessageId(),
                    ]);
                } catch (\Exception $e) {
                    // Ignore deletion errors
                }
            }
        }
        // Note: We don't delete the screenshot file as it's cached for reuse
    }

    /**
     * Check if an external file is already cached.
     */
    protected function isExternalFileCached(string $url): bool
    {
        $urlHash = md5($url);
        $storageDir = storage_path('app/public/external-attachments');

        if (! is_dir($storageDir)) {
            return false;
        }

        $existingFiles = glob($storageDir.'/'.$urlHash.'_*');

        return ! empty($existingFiles) && file_exists($existingFiles[0]);
    }

    /**
     * Check if any attachments need to be downloaded (not cached).
     */
    protected function hasUncachedExternalAttachments(\Illuminate\Support\Collection $attachments): bool
    {
        foreach ($attachments as $path) {
            if ($this->isExternalUrl($path) && ! $this->isExternalFileCached($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a path is an external URL.
     */
    protected function isExternalUrl(string $path): bool
    {
        // Check if it's a full URL with http/https
        if (! preg_match('#^https?://#i', $path)) {
            return false;
        }

        $appUrl = rtrim(config('app.url'), '/');
        $parsedPath = parse_url($path);
        $parsedAppUrl = parse_url($appUrl);

        $pathHost = $parsedPath['host'] ?? '';
        $appHost = $parsedAppUrl['host'] ?? '';

        return $pathHost !== $appHost;
    }

    /**
     * Resolve an attachment path to a local file path.
     *
     * For internal paths, returns the disk path directly.
     * For external URLs, fetches and caches the file, then returns the cached path.
     * Returns null if the file cannot be resolved.
     *
     * @return array{path: string, filename: string}|null
     */
    protected function resolveAttachmentPath(string $pathOrUrl): ?array
    {
        $disk = Storage::disk('public');

        if ($this->isExternalUrl($pathOrUrl)) {
            // External URL - fetch and cache
            return $this->fetchAndCacheExternalFile($pathOrUrl);
        }

        // Internal path - use disk
        $fullPath = $disk->path($pathOrUrl);

        if (! file_exists($fullPath)) {
            Log::warning('Attachment file not found', ['path' => $pathOrUrl]);

            return null;
        }

        return [
            'path' => $fullPath,
            'filename' => basename($pathOrUrl),
        ];
    }

    /**
     * Fetch an external file and cache it permanently.
     * Uses URL hash for permanent file-based caching - files are never re-downloaded.
     *
     * @return array{path: string, filename: string}|null
     */
    protected function fetchAndCacheExternalFile(string $url): ?array
    {
        // Create a unique hash based on the URL for permanent storage
        $urlHash = md5($url);

        // Storage directory for permanent external attachments
        $storageDir = storage_path('app/public/external-attachments');
        if (! is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        // Check if we already have this file (permanent cache - no TTL)
        $existingFiles = glob($storageDir.'/'.$urlHash.'_*');
        if (! empty($existingFiles)) {
            $existingPath = $existingFiles[0];
            if (file_exists($existingPath)) {
                return [
                    'path' => $existingPath,
                    'filename' => basename($existingPath),
                ];
            }
        }

        try {
            // Fetch the external file
            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                Log::warning('Failed to fetch external attachment', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            // Determine filename from URL or Content-Disposition header
            $filename = $this->extractFilenameFromResponse($url, $response);

            // Save permanently with URL hash prefix (ensures uniqueness)
            $localPath = $storageDir.'/'.$urlHash.'_'.$filename;
            file_put_contents($localPath, $response->body());

            return [
                'path' => $localPath,
                'filename' => $filename,
            ];
        } catch (\Exception $e) {
            Log::warning('Exception fetching external attachment', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract filename from URL or HTTP response headers.
     */
    protected function extractFilenameFromResponse(string $url, \Illuminate\Http\Client\Response $response): string
    {
        // Try Content-Disposition header first
        $contentDisposition = $response->header('Content-Disposition');
        if ($contentDisposition && preg_match('/filename[^;=\n]*=([\'"]?)([^\'";\n]+)\1/', $contentDisposition, $matches)) {
            return $matches[2];
        }

        // Extract from URL path
        $urlPath = parse_url($url, PHP_URL_PATH);
        $filename = $urlPath ? basename($urlPath) : null;

        // If no extension, try to determine from Content-Type
        if ($filename && ! pathinfo($filename, PATHINFO_EXTENSION)) {
            $contentType = $response->header('Content-Type');
            $extension = $this->mimeToExtension($contentType);
            if ($extension) {
                $filename .= '.'.$extension;
            }
        }

        return $filename ?: 'attachment_'.time();
    }

    /**
     * Convert MIME type to file extension.
     */
    protected function mimeToExtension(?string $mimeType): ?string
    {
        if (! $mimeType) {
            return null;
        }

        // Extract base mime type (without charset etc.)
        $mimeType = explode(';', $mimeType)[0];
        $mimeType = trim($mimeType);

        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'text/plain' => 'txt',
            'text/html' => 'html',
        ];

        return $mimeMap[$mimeType] ?? null;
    }

    /**
     * Send quick response attachments (images/files).
     *
     * @param  Message  $message  The original message
     * @param  Page  $page  The page being sent
     * @param  string  $caption  The caption for the attachment
     * @param  string|null  $replyMarkup  The inline keyboard markup (JSON string)
     * @param  \Illuminate\Support\Collection  $attachments  The attachments collection
     */
    protected function sendQuickResponseAttachments(Message $message, Page $page, string $caption, ?string $replyMarkup, \Illuminate\Support\Collection $attachments): void
    {
        if ($attachments->isEmpty()) {
            return;
        }

        $chatId = $message->getChat()->getId();
        $loadingMessage = null;

        // Check if any external files need to be downloaded
        if ($this->hasUncachedExternalAttachments($attachments)) {
            $loadingMessage = $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '⏳ جاري تحميل الملفات...',
                'reply_to_message_id' => $message->getMessageId(),
            ]);
        }

        try {
            // Resolve all attachments to local paths, filtering out any that fail
            $resolvedAttachments = $attachments
                ->map(fn ($path) => $this->resolveAttachmentPath($path))
                ->filter()
                ->values();

            if ($resolvedAttachments->isEmpty()) {
                // All attachments failed to resolve, fall back to text-only or screenshot
                Log::warning('All attachments failed to resolve', ['page_id' => $page->id]);

                return;
            }

            if ($resolvedAttachments->count() > 1) {
                // Check if all attachments are images
                $allImages = $resolvedAttachments->every(function ($attachment) {
                    $mime = mime_content_type($attachment['path']) ?? '';

                    return str_starts_with($mime, 'image/');
                });

                // Send all attachments as a media group (single message)
                $media = [];
                $payload = [];

                foreach ($resolvedAttachments as $index => $attachment) {
                    $fullPath = $attachment['path'];
                    $filename = $attachment['filename'];
                    $mime = mime_content_type($fullPath) ?? '';

                    // Determine media type and create InputFile
                    $inputFile = InputFile::create($fullPath, $filename);

                    // For media groups, we need to use attach://filename format
                    // Sanitize filename for attach name (remove special characters)
                    $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
                    $attachName = "attach_{$index}_{$safeFilename}";

                    // If all are images, use photo type; otherwise use document for all
                    if ($allImages && str_starts_with($mime, 'image/')) {
                        $mediaItem = [
                            'type' => 'photo',
                            'media' => "attach://{$attachName}",
                        ];
                    } else {
                        // Send as document (including images if there's a mix)
                        $mediaItem = [
                            'type' => 'document',
                            'media' => "attach://{$attachName}",
                        ];
                    }

                    // Add caption only to the first item
                    if ($index === 0) {
                        $mediaItem['caption'] = $caption;
                        $mediaItem['parse_mode'] = 'HTML';
                    }

                    $media[] = $mediaItem;

                    // Add the actual file to payload with attach name
                    $payload[$attachName] = $inputFile;
                }

                $payload['chat_id'] = $chatId;
                $payload['media'] = json_encode($media);
                $payload['reply_to_message_id'] = $message->getMessageId();

                if ($replyMarkup) {
                    $payload['reply_markup'] = $replyMarkup;
                }

                try {
                    $this->telegram->sendMediaGroup($payload);
                } catch (\Throwable $e) {
                    Log::warning('Failed to send media group with buttons, retrying without buttons', [
                        'page_id' => $page->id,
                        'error' => $e->getMessage(),
                    ]);

                    unset($payload['reply_markup']);
                    $this->telegram->sendMediaGroup($payload);
                }
            } else {
                // Single attachment - send as photo or document
                $attachment = $resolvedAttachments->first();
                $fullPath = $attachment['path'];
                $filename = $attachment['filename'];
                $mime = mime_content_type($fullPath) ?? '';

                $payload = [
                    'chat_id' => $chatId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $message->getMessageId(),
                ];

                if ($replyMarkup) {
                    $payload['reply_markup'] = $replyMarkup;
                }

                try {
                    if (str_starts_with($mime, 'image/')) {
                        $payload['photo'] = InputFile::create($fullPath, $filename);
                        $this->telegram->sendPhoto($payload);
                    } else {
                        $payload['document'] = InputFile::create($fullPath, $filename);
                        $this->telegram->sendDocument($payload);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to send attachment with buttons, retrying without buttons', [
                        'page_id' => $page->id,
                        'error' => $e->getMessage(),
                    ]);

                    unset($payload['reply_markup']);

                    if (str_starts_with($mime, 'image/')) {
                        $payload['photo'] = $payload['photo'] ?? InputFile::create($fullPath, $filename);
                        $this->telegram->sendPhoto($payload);
                    } else {
                        $payload['document'] = $payload['document'] ?? InputFile::create($fullPath, $filename);
                        $this->telegram->sendDocument($payload);
                    }
                }
            }
        } finally {
            // Delete loading message if it was sent
            if ($loadingMessage) {
                try {
                    $this->telegram->deleteMessage([
                        'chat_id' => $chatId,
                        'message_id' => $loadingMessage->getMessageId(),
                    ]);
                } catch (\Exception $e) {
                    // Ignore deletion errors
                }
            }
        }
    }

    /**
     * Group buttons by size to create keyboard rows.
     *
     * Buttons are grouped sequentially: each button's size determines
     * how many buttons of that size should be in its row.
     *
     * @param  array  $buttons  Array of button arrays with 'text', 'url', and 'size' keys
     * @return array Array of button rows for inline_keyboard
     */
    protected function groupButtonsBySize(array $buttons): array
    {
        $keyboard = [];
        $currentRow = [];
        $currentRowSize = null;
        $buttonsNeeded = 0;

        foreach ($buttons as $button) {
            $size = $button['size'] ?? 'full';

            // Determine buttons per row based on size
            $buttonsPerRow = match ($size) {
                'half' => 2,
                'third' => 3,
                default => 1, // 'full' or unknown
            };

            // If this button has a different size than the current row, finalize the row
            if ($currentRowSize !== null && ($currentRowSize !== $size || count($currentRow) >= $buttonsNeeded)) {
                if (! empty($currentRow)) {
                    $keyboard[] = $currentRow;
                }
                $currentRow = [];
                $currentRowSize = null;
                $buttonsNeeded = 0;
            }

            // Start a new row if needed
            if (empty($currentRow)) {
                $currentRowSize = $size;
                $buttonsNeeded = $buttonsPerRow;
            }

            // Create button data (remove size, keep only text and url)
            $buttonData = [
                'text' => $button['text'],
                'url' => $button['url'],
            ];

            $currentRow[] = $buttonData;

            // If row is full, add it to keyboard and start new row
            if (count($currentRow) >= $buttonsNeeded) {
                $keyboard[] = $currentRow;
                $currentRow = [];
                $currentRowSize = null;
                $buttonsNeeded = 0;
            }
        }

        // Add remaining buttons as a row
        if (! empty($currentRow)) {
            $keyboard[] = $currentRow;
        }

        return $keyboard;
    }
}
