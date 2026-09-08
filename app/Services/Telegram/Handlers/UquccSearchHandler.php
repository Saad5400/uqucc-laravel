<?php

namespace App\Services\Telegram\Handlers;

use App\Models\Page;
use App\Services\QuickResponseService;
use App\Services\Telegram\PageReply;
use App\Services\Telegram\PageReplyComposer;
use App\Services\Telegram\Traits\SearchesPages;
use App\Support\Disk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Message;

/**
 * Answers a message that names a page — «دليل X», «بحث X», a bare title, or any
 * message carrying a smart-search title — with that page's reply.
 *
 * What the reply says is {@see PageReplyComposer}'s business; this handler finds
 * the page and does the sending, in as few messages as the Bot API allows: the
 * page's few images with the reply as their caption when they can carry it,
 * otherwise the images first and the reply — title, content in a collapsed
 * quote, buttons — in the message under them.
 */
class UquccSearchHandler extends BaseHandler
{
    use SearchesPages;

    /** Telegram's ceiling for one album. */
    private const MEDIA_GROUP_SIZE = 10;

    protected array $exceptionWords = [
        // 'لابتوب'
    ];

    public function __construct(
        Api $telegram,
        protected QuickResponseService $quickResponses,
        protected PageReplyComposer $composer,
    ) {
        parent::__construct($telegram);
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

        // Check if it matches "دليل <query>" or "بحث <query>" or is one of the exception words
        $isCommand = false;
        $isAggressiveSearch = false;
        $query = null;

        if (preg_match('/^دليل\s+(.+)$/u', $content, $matches)) {
            $isCommand = true;
            $query = $matches[1];
        } elseif (preg_match('/^بحث\s+(.+)$/u', $content, $matches)) {
            $isCommand = true;
            $isAggressiveSearch = true;
            $query = $matches[1];
        } elseif (in_array($content, $this->exceptionWords)) {
            $isCommand = true;
            $query = $content;
        }

        if ($isCommand) {
            if ($isAggressiveSearch) {
                $this->aggressiveSearchAndRespond($message, $query);
            } else {
                $this->searchAndRespond($message, $query);
            }

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
     * Get the message ID to reply to.
     * If the user's message was a reply, reply to that original message instead.
     */
    protected function getReplyToMessageId(Message $message): int
    {
        $replyToMessage = $message->getReplyToMessage();
        if ($replyToMessage) {
            return $replyToMessage->getMessageId();
        }

        return $message->getMessageId();
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
                'reply_to_message_id' => $this->getReplyToMessageId($message),
            ]);

            // Delete both the user message and bot response after 5 seconds
            $this->deleteMessagesAfterDelay($message, $sentMessage);

            return;
        }

        $this->sendPageResult($message, $page);
    }

    protected function aggressiveSearchAndRespond(Message $message, string $query): void
    {
        $this->trackCommand($message, 'بحث');

        $page = $this->aggressiveSearch($query);

        if (! $page) {
            $sentMessage = $this->telegram->sendMessage([
                'chat_id' => $message->getChat()->getId(),
                'text' => 'لم أتمكن من العثور على أي صفحة مطابقة',
                'reply_to_message_id' => $this->getReplyToMessageId($message),
            ]);

            // Delete both the user message and bot response after 5 seconds
            $this->deleteMessagesAfterDelay($message, $sentMessage);

            return;
        }

        $this->sendPageResult($message, $page);
    }

    /**
     * Send a page: its files and its text, in as few messages as Telegram
     * allows — one message when the files can carry the text as their caption,
     * the files first and the text under them when they cannot.
     */
    protected function sendPageResult(Message $message, Page $page): void
    {
        $reply = $this->composer->compose($page);

        if ($reply->attachments === []) {
            $this->sendReplyText($message, $reply);

            return;
        }

        $chatId = $message->getChat()->getId();
        $loadingMessage = null;

        // Tell the reader something is coming while external files download.
        if ($this->hasUncachedExternalAttachments(collect($reply->attachments))) {
            $loadingMessage = $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '⏳ جاري تحميل الملفات...',
                'reply_to_message_id' => $this->getReplyToMessageId($message),
            ]);
        }

        try {
            $resolved = collect($reply->attachments)
                ->map(fn (string $path) => $this->resolveAttachmentPath($path))
                ->filter()
                ->values();

            if ($resolved->isEmpty()) {
                Log::warning('All attachments failed to resolve', ['page_id' => $page->id]);
            }

            $this->sendAttachmentsWithReply($message, $resolved, $reply);
        } finally {
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
     * The page's files and its reply, in as few messages as the Bot API allows.
     *
     * A file takes a caption, so a reply short enough for one rides on the file
     * itself: the reader gets the picture, the text and the buttons as a single
     * message, the way a person would have sent it. An album is the exception —
     * Telegram hangs no buttons under one — so a reply with buttons keeps them
     * and goes as its own message under the album. A reply too long for a
     * caption also goes on its own rather than lose the content it carries.
     *
     * @param  Collection<int, array{path: string, filename: string}>  $resolved
     */
    protected function sendAttachmentsWithReply(Message $message, Collection $resolved, PageReply $reply): void
    {
        $albums = $resolved->chunk(self::MEDIA_GROUP_SIZE)->values();

        $carriesCaption = $albums->count() === 1
            && $reply->fitsInCaption()
            && ($resolved->count() === 1 || $reply->keyboard === []);

        if ($carriesCaption) {
            $this->sendAttachmentGroup($message, $albums->first()->values(), $reply);

            return;
        }

        foreach ($albums as $album) {
            $this->sendAttachmentGroup($message, $album->values(), null);
        }

        $this->sendReplyText($message, $reply);
    }

    /**
     * One album of up to ten files, or a lone file on its own, with the reply
     * as its caption when it was given one.
     *
     * @param  Collection<int, array{path: string, filename: string}>  $attachments
     */
    protected function sendAttachmentGroup(Message $message, Collection $attachments, ?PageReply $reply): void
    {
        if ($attachments->count() === 1) {
            $this->sendSingleAttachment($message, $attachments->first(), $reply);

            return;
        }

        $this->sendMediaGroup($message, $attachments, $reply);
    }

    /**
     * The text message. Telegram parses the markup server-side and rejects the
     * whole message on anything it dislikes, so a refused quoted reply is sent
     * again without the quote rather than not at all.
     */
    protected function sendReplyText(Message $message, PageReply $reply): void
    {
        $this->withQuoteFallback($reply, function (string $text) use ($message, $reply): void {
            $params = [
                'chat_id' => $message->getChat()->getId(),
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $this->getReplyToMessageId($message),
                'link_preview_options' => $reply->linkPreviewOptions(),
            ];

            if ($markup = $reply->replyMarkup()) {
                $params['reply_markup'] = $markup;
            }

            $this->telegram->sendMessage($params);
        });
    }

    /**
     * Send the reply, and send it once more unquoted when Telegram refuses the
     * markup — it parses HTML server-side and rejects the whole message over
     * anything it dislikes, so a reply it turned down still arrives, plainly.
     *
     * @param  callable(string): void  $send  Sends the reply carrying the given text
     */
    protected function withQuoteFallback(PageReply $reply, callable $send): void
    {
        try {
            $send($reply->text);
        } catch (\Throwable $e) {
            if ($reply->fallbackText === null) {
                throw $e;
            }

            Log::warning('Telegram refused the quoted page reply, resending it unquoted', [
                'error' => $e->getMessage(),
            ]);

            $send($reply->fallbackText);
        }
    }

    /**
     * @param  array{path: string, filename: string}  $attachment
     */
    protected function sendSingleAttachment(Message $message, array $attachment, ?PageReply $reply): void
    {
        $isImage = str_starts_with(mime_content_type($attachment['path']) ?: '', 'image/');

        $send = function (?string $caption) use ($message, $attachment, $isImage, $reply): void {
            $payload = [
                'chat_id' => $message->getChat()->getId(),
                'reply_to_message_id' => $this->getReplyToMessageId($message),
            ];

            if ($caption !== null) {
                $payload['caption'] = $caption;
                $payload['parse_mode'] = 'HTML';

                if ($markup = $reply?->replyMarkup()) {
                    $payload['reply_markup'] = $markup;
                }
            }

            $file = InputFile::create($attachment['path'], $attachment['filename']);

            if ($isImage) {
                $payload['photo'] = $file;
                $this->telegram->sendPhoto($payload);
            } else {
                $payload['document'] = $file;
                $this->telegram->sendDocument($payload);
            }
        };

        if ($reply === null) {
            $send(null);

            return;
        }

        $this->withQuoteFallback($reply, $send);
    }

    /**
     * One album of up to ten files. Photos when every file is an image,
     * documents otherwise — Telegram will not mix the two in one group. A
     * caption goes on the first file, which is where Telegram draws it for
     * the album as a whole.
     *
     * @param  Collection<int, array{path: string, filename: string}>  $attachments
     */
    protected function sendMediaGroup(Message $message, Collection $attachments, ?PageReply $reply): void
    {
        $allImages = $attachments->every(fn (array $attachment): bool => str_starts_with(mime_content_type($attachment['path']) ?: '', 'image/'));

        $send = function (?string $caption) use ($message, $attachments, $allImages): void {
            $media = [];
            $payload = [];

            foreach ($attachments as $index => $attachment) {
                // Sanitize filename for attach name (remove special characters)
                $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $attachment['filename']);
                $attachName = "attach_{$index}_{$safeFilename}";

                $item = [
                    'type' => $allImages ? 'photo' : 'document',
                    'media' => "attach://{$attachName}",
                ];

                if ($index === 0 && $caption !== null) {
                    $item['caption'] = $caption;
                    $item['parse_mode'] = 'HTML';
                }

                $media[] = $item;

                $payload[$attachName] = InputFile::create($attachment['path'], $attachment['filename']);
            }

            $payload['chat_id'] = $message->getChat()->getId();
            $payload['media'] = json_encode($media);
            $payload['reply_to_message_id'] = $this->getReplyToMessageId($message);

            $this->telegram->sendMediaGroup($payload);
        };

        if ($reply === null) {
            $send(null);

            return;
        }

        $this->withQuoteFallback($reply, $send);
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
     *
     * @param  Collection<int, string>  $attachments
     */
    protected function hasUncachedExternalAttachments(Collection $attachments): bool
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
        $disk = Storage::disk(Disk::MEDIA);

        if ($this->isExternalUrl($pathOrUrl)) {
            // External URL - fetch and cache
            return $this->fetchAndCacheExternalFile($pathOrUrl);
        }

        // Internal path - resolve on the media disk
        if (! $disk->exists($pathOrUrl)) {
            Log::warning('Attachment file not found', ['path' => $pathOrUrl]);

            return null;
        }

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return [
                'path' => $disk->path($pathOrUrl),
                'filename' => basename($pathOrUrl),
            ];
        }

        // Remote media disk (S3 in production): cache a local copy so the
        // Telegram SDK can upload from a real filesystem path. Keyed by the
        // disk path, reused across sends.
        $cacheDir = storage_path('app/cache/media-attachments');
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $localPath = $cacheDir.'/'.md5($pathOrUrl).'_'.basename($pathOrUrl);

        if (! file_exists($localPath)) {
            $contents = $disk->get($pathOrUrl);

            if ($contents === null || file_put_contents($localPath, $contents) === false) {
                Log::warning('Attachment file could not be copied locally', ['path' => $pathOrUrl]);

                return null;
            }
        }

        return [
            'path' => $localPath,
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
}
