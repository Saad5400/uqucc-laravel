<?php

namespace App\Services\Telegram\Handlers;

use App\Models\Page;
use App\Services\QuickResponseService;
use App\Services\TelegramMarkdownService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Message;

class UquccSearchHandler extends BaseHandler
{
    protected array $exceptionWords = ['لابتوب'];

    public function __construct(
        \Telegram\Bot\Api $telegram,
        protected QuickResponseService $quickResponses,
        protected TelegramMarkdownService $markdownService
    ) {
        parent::__construct($telegram);
    }

    public function handle(Message $message): void
    {
        $text = $message->getText();
        // Ensure getText() returns a string (handle edge cases where it might be an array)
        $content = is_string($text) ? trim($text) : '';

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

        if (! $isCommand) {
            return;
        }

        $this->searchAndRespond($message, $query);
    }

    protected function searchAndRespond(Message $message, string $query): void
    {
        $page = $this->quickResponses->search($query);

        if (! $page) {
            $this->reply($message, 'الصفحة غير موجودة');

            return;
        }

        $this->sendPageResult($message, $page);
    }

    protected function sendPageResult(Message $message, Page $page): void
    {
        $textContent = $this->buildTextContent($page);
        $replyMarkup = $this->buildReplyMarkup($page);

        // Check if there are attachments
        $attachments = collect($page->quick_response_attachments ?? [])
            ->filter()
            ->values();

        if ($attachments->isNotEmpty() && $page->quick_response_enabled) {
            // Send attachments with text as caption
            $this->sendQuickResponseAttachments($message, $page, $textContent, $replyMarkup);
        } elseif (!$page->quick_response_enabled) {
            // When quick response is disabled, send screenshot with text as caption
            $this->sendScreenshotWithText($message, $page, $textContent, $replyMarkup);
        } else {
            // Send text message only
            $params = [
                'chat_id' => $message->getChat()->getId(),
                'text' => $textContent,
                'parse_mode' => 'MarkdownV2',
            ];

            if ($replyMarkup) {
                $params['reply_markup'] = $replyMarkup;
            }

            $this->telegram->sendMessage($params);
        }
    }

    protected function buildTextContent(Page $page): string
    {
        $pageUrl = url($page->slug);

        // Ensure title is a string
        $title = is_string($page->title) ? $page->title : (string) $page->title;
        $title = $this->escapeMarkdownV2($title);
        $lines = ["*{$title}*"];

        if ($page->quick_response_enabled) {
            if ($page->quick_response_message) {
                // Ensure message is a string
                $messageText = is_string($page->quick_response_message) 
                    ? $page->quick_response_message 
                    : (string) $page->quick_response_message;
                // Convert markdown to Telegram MarkdownV2 format
                $lines[] = $this->markdownService->toMarkdownV2($messageText);
            }

            if ($page->quick_response_send_link) {
                $escapedUrl = $this->escapeMarkdownV2($pageUrl);
                $lines[] = "🔗 الرابط: [{$escapedUrl}]({$pageUrl})";
            }
        } else {
            // Fallback content when quick responses are not configured
            $htmlContent = $page->html_content;
            if (is_array($htmlContent)) {
                // If html_content is an array (JSON decoded), skip preview
                $preview = '';
            } else {
                $preview = Str::limit(strip_tags((string) $htmlContent), 300);
            }
            if ($preview) {
                $lines[] = $this->escapeMarkdownV2($preview);
            }

            $escapedUrl = $this->escapeMarkdownV2($pageUrl);
            $lines[] = "🔗 الرابط: [{$escapedUrl}]({$pageUrl})";
        }

        return implode("\n\n", array_filter($lines));
    }

    protected function buildReplyMarkup(Page $page): ?string
    {
        if (!$page->quick_response_buttons || !is_array($page->quick_response_buttons)) {
            return null;
        }

        $buttons = collect($page->quick_response_buttons)
            ->filter(function ($btn) {
                if (!is_array($btn)) {
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

    protected function getScreenshotCacheKey(Page $page): string
    {
        // Use page slug and updated_at timestamp to create versioned cache key
        $version = $page->updated_at ? $page->updated_at->timestamp : '0';
        $slug = str_replace('/', '_', trim($page->slug, '/')) ?: 'home';
        return config('app-cache.keys.screenshot') . ":{$slug}:{$version}";
    }

    protected function getScreenshotPath(Page $page): string
    {
        $slug = str_replace('/', '_', trim($page->slug, '/')) ?: 'home';
        $version = $page->updated_at ? $page->updated_at->timestamp : '0';
        $filename = "{$slug}_{$version}.webp";
        return storage_path("app/public/screenshots/{$filename}");
    }

    protected function takeScreenshot(Page $page): string
    {
        $cacheKey = $this->getScreenshotCacheKey($page);
        $screenshotPath = $this->getScreenshotPath($page);
        
        // Check if cached screenshot exists and is valid
        if (file_exists($screenshotPath) && Cache::has($cacheKey)) {
            return $screenshotPath;
        }
        
        // Default dimensions matching screenshot.ts (720x377 for 1.91:1 aspect ratio)
        $width = 720;
        $height = 377;
        
        $pageUrl = url($page->slug);
        
        // Ensure screenshots directory exists
        $screenshotsDir = dirname($screenshotPath);
        if (!is_dir($screenshotsDir)) {
            mkdir($screenshotsDir, 0755, true);
        }
        
        try {
            $browsershot = Browsershot::url($pageUrl)
                ->windowSize($width, $height)
                ->deviceScaleFactor(1)
                ->waitUntilNetworkIdle()
                ->delay(500) // Wait 500ms after network idle to ensure DOM is fully rendered
                ->timeout(60)
                ->dismissDialogs()
                ->setScreenshotType('webp', 80)
                ->addChromiumArguments([
                    'no-sandbox',
                    'disable-setuid-sandbox',
                    'disable-dev-shm-usage',
                    'disable-gpu',
                    'disable-web-security',
                    'disable-extensions',
                    'disable-plugins',
                    'disable-default-apps',
                    'disable-background-timer-throttling',
                    'disable-backgrounding-occluded-windows',
                    'disable-renderer-backgrounding',
                    'disable-features=TranslateUI',
                    'disable-component-update',
                    'disable-domain-reliability',
                    'disable-sync',
                    'disable-client-side-phishing-detection',
                    'disable-permissions-api',
                    'disable-notifications',
                    'disable-desktop-notifications',
                    'disable-background-networking',
                    'memory-pressure-off',
                    'max_old_space_size=128',
                    'aggressive-cache-discard',
                ]);
            
            // Inject CSS to hide .screenshot-hidden elements
            // This is more reliable than JavaScript evaluation as it applies immediately
            $browsershot->setOption('addStyleTag', [
                [
                    'content' => '.screenshot-hidden { display: none !important; visibility: hidden !important; }',
                ],
            ]);
            
            // Also use evaluate as a fallback and to adjust scrollbar
            $browsershot->evaluate("
                document.querySelectorAll('.screenshot-hidden').forEach(e => {
                    e.style.display = 'none';
                    e.style.visibility = 'hidden';
                });
                document.documentElement.style.scrollbarGutter = 'auto';
            ");
            
            $browsershot->save($screenshotPath);
            
            // Cache the screenshot path for the configured TTL
            Cache::put($cacheKey, $screenshotPath, config('app-cache.screenshots.ttl'));
            
            return $screenshotPath;
        } catch (\Exception $e) {
            // Clean up on error
            if (file_exists($screenshotPath)) {
                @unlink($screenshotPath);
            }
            throw $e;
        }
    }

    protected function sendScreenshotWithText(Message $message, Page $page, string $caption, ?string $replyMarkup = null): void
    {
        // Get cached or generate new screenshot
        $screenshotPath = $this->takeScreenshot($page);
        
        $params = [
            'chat_id' => $message->getChat()->getId(),
            'photo' => InputFile::create($screenshotPath, 'screenshot.webp'),
            'caption' => $caption,
            'parse_mode' => 'MarkdownV2',
        ];

        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        $this->telegram->sendPhoto($params);
        // Note: We don't delete the screenshot file as it's cached for reuse
    }

    protected function sendQuickResponseAttachments(Message $message, Page $page, string $caption, ?string $replyMarkup = null): void
    {
        $attachments = collect($page->quick_response_attachments ?? [])
            ->filter()
            ->values();

        if ($attachments->isEmpty()) {
            return;
        }

        $disk = Storage::disk('public');
        $chatId = $message->getChat()->getId();

        if ($attachments->count() > 1) {
            // Check if all attachments are images
            $allImages = $attachments->every(function ($path) use ($disk) {
                $fullPath = $disk->path($path);
                $mime = mime_content_type($fullPath) ?? '';
                return str_starts_with($mime, 'image/');
            });

            // Send all attachments as a media group (single message)
            $media = [];
            $payload = [];
            
            foreach ($attachments as $index => $path) {
                $fullPath = $disk->path($path);
                $filename = basename($path);
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
                    $mediaItem['parse_mode'] = 'MarkdownV2';
                }

                $media[] = $mediaItem;
                
                // Add the actual file to payload with attach name
                $payload[$attachName] = $inputFile;
            }

            $payload['chat_id'] = $chatId;
            $payload['media'] = json_encode($media);

            // Note: sendMediaGroup doesn't support reply_markup parameter
            // If buttons are provided, they will be ignored
            $this->telegram->sendMediaGroup($payload);
        } else {
            // Single attachment - send as photo or document
            $path = $attachments->first();
            $fullPath = $disk->path($path);
            $filename = basename($path);
            $mime = mime_content_type($fullPath) ?? '';

            $payload = [
                'chat_id' => $chatId,
                'caption' => $caption,
                'parse_mode' => 'MarkdownV2',
            ];

            if ($replyMarkup) {
                $payload['reply_markup'] = $replyMarkup;
            }

            if (str_starts_with($mime, 'image/')) {
                $payload['photo'] = InputFile::create($fullPath, $filename);
                $this->telegram->sendPhoto($payload);
            } else {
                $payload['document'] = InputFile::create($fullPath, $filename);
                $this->telegram->sendDocument($payload);
            }
        }
    }

    /**
     * Group buttons by size to create keyboard rows.
     * 
     * Buttons are grouped sequentially: each button's size determines
     * how many buttons of that size should be in its row.
     * 
     * @param array $buttons Array of button arrays with 'text', 'url', and 'size' keys
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
            $buttonsPerRow = match($size) {
                'half' => 2,
                'third' => 3,
                default => 1, // 'full' or unknown
            };
            
            // If this button has a different size than the current row, finalize the row
            if ($currentRowSize !== null && ($currentRowSize !== $size || count($currentRow) >= $buttonsNeeded)) {
                if (!empty($currentRow)) {
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
        if (!empty($currentRow)) {
            $keyboard[] = $currentRow;
        }
        
        return $keyboard;
    }
}
