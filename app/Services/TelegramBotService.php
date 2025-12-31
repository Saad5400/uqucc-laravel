<?php

namespace App\Services;

use App\Services\Telegram\ContentParser;
use App\Services\Telegram\Handlers\DeepSeekChatHandler;
use App\Services\Telegram\Handlers\EditLinkHandler;
use App\Services\Telegram\Handlers\HelpHandler;
use App\Services\Telegram\Handlers\InfoHandler;
use App\Services\Telegram\Handlers\InviteLinkHandler;
use App\Services\Telegram\Handlers\JavaExecutionHandler;
use App\Services\Telegram\Handlers\LoginHandler;
use App\Services\Telegram\Handlers\PageManagementHandler;
use App\Services\Telegram\Handlers\PrivateForwardHandler;
use App\Services\Telegram\Handlers\PythonExecutionHandler;
use App\Services\Telegram\Handlers\UquccListHandler;
use App\Services\Telegram\Handlers\UquccSearchHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

class TelegramBotService
{
    protected const OFFSET_CACHE_KEY = 'telegram_bot_offset';

    protected const PROCESSED_UPDATE_PREFIX = 'telegram_processed_update:';

    protected Api $telegram;

    protected array $handlers = [];

    protected PageManagementHandler $pageManagementHandler;

    public function __construct()
    {
        $this->telegram = new Api(config('services.telegram.token'));

        // Initialize page management handler (needs special handling for callbacks)
        $this->pageManagementHandler = new PageManagementHandler($this->telegram, app(ContentParser::class));

        // Initialize handlers
        $this->handlers = [
            new HelpHandler($this->telegram),
            new LoginHandler($this->telegram),
            $this->pageManagementHandler,
            new EditLinkHandler($this->telegram),
            new UquccSearchHandler($this->telegram, app(QuickResponseService::class), app(TipTapContentExtractor::class), app(OgImageService::class)),
            new UquccListHandler($this->telegram),
            new PythonExecutionHandler($this->telegram),
            new JavaExecutionHandler($this->telegram),
            new DeepSeekChatHandler($this->telegram),
            new InfoHandler($this->telegram),
            new PrivateForwardHandler($this->telegram),
            new InviteLinkHandler($this->telegram),
        ];
    }

    /**
     * Run the bot using long polling.
     *
     * - Offset is persisted in cache (survives restarts)
     * - Deduplication via cache prevents processing same update twice
     * - Synchronous processing for speed
     */
    public function run(): void
    {
        echo 'Bot is ready!', PHP_EOL;
        echo 'Logged in as '.$this->telegram->getMe()->getFirstName(), PHP_EOL;

        // Load persisted offset from cache (survives restarts)
        $offset = (int) Cache::get(self::OFFSET_CACHE_KEY, 0);
        echo "Starting from offset: {$offset}", PHP_EOL;

        while (true) {
            try {
                $updates = $this->telegram->getUpdates([
                    'offset' => $offset,
                    'timeout' => 30,
                ]);

                foreach ($updates as $update) {
                    $updateId = $update->getUpdateId();

                    // Update offset BEFORE handling to prevent reprocessing on error
                    $offset = $updateId + 1;
                    Cache::put(self::OFFSET_CACHE_KEY, $offset);

                    // Skip if already processed (deduplication)
                    $cacheKey = self::PROCESSED_UPDATE_PREFIX.$updateId;
                    if (Cache::has($cacheKey)) {
                        continue;
                    }

                    // Mark as processed before handling
                    Cache::put($cacheKey, true, now()->addHours(24));

                    // Process synchronously
                    $this->handleUpdate($update);
                }
            } catch (\Exception $e) {
                Log::error('Telegram bot polling error', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile().':'.$e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
                sleep(5);
            }
        }
    }

    /**
     * Handle a single Telegram update.
     */
    protected function handleUpdate(Update $update): void
    {
        try {
            // Handle callback queries (inline button presses)
            $callbackQuery = $update->getCallbackQuery();
            if ($callbackQuery) {
                try {
                    $this->pageManagementHandler->handleCallback($callbackQuery);
                } catch (\Exception $e) {
                    Log::error('Telegram callback error', [
                        'message' => $e->getMessage(),
                        'file' => $e->getFile().':'.$e->getLine(),
                    ]);
                }

                return;
            }

            $message = $update->getMessage();

            if (! $message || $message->getFrom()->getIsBot()) {
                return;
            }

            foreach ($this->handlers as $handler) {
                try {
                    $handler->handle($message);
                } catch (\Exception $e) {
                    Log::error('Telegram handler error', [
                        'handler' => get_class($handler),
                        'message' => $e->getMessage(),
                        'file' => $e->getFile().':'.$e->getLine(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Telegram update handling error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
        }
    }

    /**
     * Reset the persisted offset.
     * Useful if you need to reprocess old updates or start fresh.
     */
    public static function resetOffset(): void
    {
        Cache::forget(self::OFFSET_CACHE_KEY);
    }

    /**
     * Get the current persisted offset.
     */
    public static function getOffset(): int
    {
        return (int) Cache::get(self::OFFSET_CACHE_KEY, 0);
    }
}
