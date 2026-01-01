<?php

namespace App\Services;

use App\Jobs\ProcessTelegramUpdate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class TelegramBotService
{
    protected const OFFSET_CACHE_KEY = 'telegram_bot_offset';

    protected const PROCESSED_UPDATE_PREFIX = 'telegram_processed_update:';

    protected Api $telegram;

    public function __construct()
    {
        $this->telegram = new Api(config('services.telegram.token'), false);
    }

    /**
     * Run the bot using long polling.
     *
     * - Offset is persisted in cache (survives restarts)
     * - Deduplication via cache prevents processing same update twice
     * - Updates are dispatched to queue for concurrent processing
     */
    public function run(): void
    {
        echo 'Bot is ready!', PHP_EOL;
        echo 'Logged in as '.$this->telegram->getMe()->getFirstName(), PHP_EOL;
        echo 'Updates will be processed concurrently via queue.', PHP_EOL;

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

                    // Mark as processed before dispatching
                    Cache::put($cacheKey, true, now()->addHours(24));

                    // Dispatch to queue for concurrent processing
                    // Convert Update object to array for serialization
                    ProcessTelegramUpdate::dispatch($update->toArray());
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
     * Reset the persisted offset.
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
