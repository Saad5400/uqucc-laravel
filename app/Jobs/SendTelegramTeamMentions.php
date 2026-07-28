<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Telegram\Bot\Api;

/**
 * Delivers the remaining batches of a «منشن فريق» blast. The handler sends
 * the header and the first batch inline; anything beyond one message is
 * fanned out here so a partial Telegram failure retries without re-running
 * the handler.
 */
class SendTelegramTeamMentions implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * Create a new job instance.
     *
     * @param  array<int, string>  $htmlChunks  Pre-rendered HTML mention batches
     */
    public function __construct(
        public int $chatId,
        public int $replyToMessageId,
        public array $htmlChunks,
    ) {
        $this->onQueue('telegram');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $telegram = $this->makeTelegram();

        foreach ($this->htmlChunks as $chunk) {
            try {
                $telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $chunk,
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $this->replyToMessageId,
                    'allow_sending_without_reply' => true,
                ]);
            } catch (\Exception) {
                // Keep delivering the remaining batches.
            }
        }
    }

    /**
     * Build the Telegram API client used to send the batches.
     */
    protected function makeTelegram(): Api
    {
        return new Api((string) config('services.telegram.token'), false);
    }
}
