<?php

namespace App\Console\Commands;

use App\Models\Ai\ChatAttachment;
use Illuminate\Console\Command;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Prunes stale anonymous assistant data: conversations (with their messages)
 * idle longer than the retention window, and chat attachments of the same
 * age (each attachment delete also removes its stored file via the model
 * hook). Visitors are anonymous sessions, so old threads are unreachable
 * anyway — this keeps the tables and the uploads disk from growing forever.
 *
 * Scheduled daily in routes/console.php.
 */
class PruneAiConversations extends Command
{
    protected $signature = 'ai:prune-conversations {--days=7 : Prune conversations and attachments idle for more than this many days}';

    protected $description = 'Delete AI assistant conversations and chat attachments older than the retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $conversationIds = Conversation::query()
            ->where('updated_at', '<', $cutoff)
            ->pluck('id');

        $messagesDeleted = 0;

        if ($conversationIds->isNotEmpty()) {
            $messagesDeleted = ConversationMessage::query()
                ->whereIn('conversation_id', $conversationIds)
                ->delete();

            Conversation::query()->whereKey($conversationIds->all())->delete();
        }

        $attachmentsDeleted = 0;

        ChatAttachment::query()
            ->where('created_at', '<', $cutoff)
            ->get()
            ->each(function (ChatAttachment $attachment) use (&$attachmentsDeleted): void {
                $attachment->delete();
                $attachmentsDeleted++;
            });

        $this->info(sprintf(
            'Pruned %d conversations (%d messages) and %d chat attachments older than %d days.',
            $conversationIds->count(),
            $messagesDeleted,
            $attachmentsDeleted,
            $days,
        ));

        return self::SUCCESS;
    }
}
