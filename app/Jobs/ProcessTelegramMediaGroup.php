<?php

namespace App\Jobs;

use App\Services\Telegram\Handlers\AiChatHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;
use Throwable;

/**
 * Runs one aggregated assistant turn for a Telegram media group (album).
 *
 * Telegram delivers an album as several separate updates sharing a
 * media_group_id, and only one part carries the «سيك …» caption. Each part is
 * buffered by {@see AiChatHandler::bufferMediaGroupPart()}; the first part
 * schedules this job with a short debounce so every sibling has landed before
 * it fires. This job then reads the buffer once and hands the caption-bearing
 * lead message plus every extractable photo/document to
 * {@see AiChatHandler::handleMediaGroup()}.
 *
 * When no part carried the «سيك» ask the album is dropped here without any
 * download, extraction, or spend — the same "never answer plain chatter" rule
 * a single message follows.
 */
class ProcessTelegramMediaGroup implements ShouldQueue
{
    use Queueable;

    /** The number of times the job may be attempted. */
    public int $tries = 1;

    /** The number of seconds the job can run before timing out. */
    public int $timeout = 120;

    public function __construct(public string $mediaGroupId)
    {
        $this->onQueue('telegram');
    }

    public function handle(): void
    {
        $buffer = Cache::pull(AiChatHandler::mediaGroupBufferKey($this->mediaGroupId));
        Cache::forget(AiChatHandler::mediaGroupScheduledKey($this->mediaGroupId));

        if (! is_array($buffer)) {
            return;
        }

        $lead = $buffer['lead'] ?? null;

        if (! is_array($lead)) {
            return;
        }

        $sources = array_values(array_filter((array) ($buffer['sources'] ?? [])));

        try {
            $telegram = new Api(config('services.telegram.token'), false);

            AiChatHandler::make($telegram)->handleMediaGroup(
                new Message($lead),
                $sources,
                (bool) ($buffer['truncated'] ?? false),
            );
        } catch (Throwable $exception) {
            Log::error('ProcessTelegramMediaGroup job failed', [
                'group_id' => $this->mediaGroupId,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile().':'.$exception->getLine(),
            ]);
        }
    }
}
