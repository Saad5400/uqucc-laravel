<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Telegram\Bot\Api;

class DeleteTelegramMessages implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * Create a new job instance.
     *
     * @param  int  $chatId  The chat ID
     * @param  array<int>  $messageIds  Array of message IDs to delete
     */
    public function __construct(
        public int $chatId,
        public array $messageIds
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $telegram = new Api(config('services.telegram.token'));

        foreach ($this->messageIds as $messageId) {
            try {
                $telegram->deleteMessage([
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                ]);
            } catch (\Exception $e) {
                // Message might already be deleted or too old
                // Silently ignore
            }
        }
    }
}

