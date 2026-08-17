<?php

namespace App\Listeners;

use App\Models\Ai\ChatAttachment;
use Saad\AiKit\Conversations\Events\ConversationsPruning;

/**
 * Cascades chat-attachment cleanup off ai-kit's conversation pruning
 * (`ai-kit:prune-conversations`, scheduled daily in routes/console.php). The
 * event fires per chunk BEFORE the doomed conversations are deleted, so their
 * attachment rows still resolve; deleting through the model fires the
 * {@see ChatAttachment} hook that removes each stored file from disk.
 *
 * Besides the cascade, every event also sweeps attachments older than the
 * retention cutoff regardless of conversation — uploads that were never sent
 * (conversation_id stays null) and files lingering in long-lived threads.
 * The sweep is idempotent, so re-running it on each chunk just finds nothing.
 */
class PruneChatAttachments
{
    public function handle(ConversationsPruning $event): void
    {
        ChatAttachment::query()
            ->whereIn('conversation_id', $event->conversationIds)
            ->orWhere('created_at', '<', $event->cutoff)
            ->lazyById(200)
            ->each(fn (ChatAttachment $attachment) => $attachment->delete());
    }
}
