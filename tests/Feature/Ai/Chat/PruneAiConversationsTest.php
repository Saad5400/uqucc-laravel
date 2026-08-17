<?php

use App\Ai\Agents\StudentAssistant;
use App\Models\Ai\ChatAttachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Pruning is ai-kit's `ai-kit:prune-conversations` command (scheduled with
 * the 7-day window pinned in config/ai-kit.php); the app's part is the
 * {@see \App\Listeners\PruneChatAttachments} listener cascading chat
 * attachments off the ConversationsPruning event.
 */

/**
 * Create a conversation (with one message) whose activity timestamp is
 * backdated without the model touching it again.
 */
function conversationIdleFor(int $days): string
{
    $conversationId = (string) Str::uuid7();

    Conversation::query()->create([
        'id' => $conversationId,
        'participant_type' => \App\Ai\Chat\SessionOwner::class,
        'participant_id' => Str::random(40),
        'title' => 'محادثة',
    ]);

    ConversationMessage::query()->create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_id' => null,
        'agent' => StudentAssistant::class,
        'role' => 'user',
        'content' => 'سؤال',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    Conversation::query()->whereKey($conversationId)->toBase()->update([
        'updated_at' => now()->subDays($days),
    ]);

    return $conversationId;
}

it('prunes conversations, messages and attachments older than the window', function () {
    Storage::fake(ChatAttachment::DISK);

    $oldConversationId = conversationIdleFor(8);
    $freshConversationId = conversationIdleFor(2);

    $oldAttachment = ChatAttachment::factory()->create();
    Storage::disk($oldAttachment->disk)->put($oldAttachment->path, 'bytes');
    ChatAttachment::query()->whereKey($oldAttachment->id)->toBase()->update(['created_at' => now()->subDays(8)]);

    $freshAttachment = ChatAttachment::factory()->create();
    Storage::disk($freshAttachment->disk)->put($freshAttachment->path, 'bytes');

    $this->artisan('ai-kit:prune-conversations', ['--days' => 7])->assertSuccessful();

    expect(Conversation::query()->whereKey($oldConversationId)->exists())->toBeFalse()
        ->and(ConversationMessage::query()->where('conversation_id', $oldConversationId)->exists())->toBeFalse()
        ->and(Conversation::query()->whereKey($freshConversationId)->exists())->toBeTrue()
        ->and(ConversationMessage::query()->where('conversation_id', $freshConversationId)->exists())->toBeTrue()
        ->and(ChatAttachment::query()->whereKey($oldAttachment->id)->exists())->toBeFalse()
        ->and(ChatAttachment::query()->whereKey($freshAttachment->id)->exists())->toBeTrue();

    Storage::disk(ChatAttachment::DISK)->assertMissing($oldAttachment->path);
    Storage::disk(ChatAttachment::DISK)->assertExists($freshAttachment->path);
});

it('cascades a pruned conversation onto its attachments even when they are fresh', function () {
    Storage::fake(ChatAttachment::DISK);

    $oldConversationId = conversationIdleFor(8);

    $boundAttachment = ChatAttachment::factory()->create(['conversation_id' => $oldConversationId]);
    Storage::disk($boundAttachment->disk)->put($boundAttachment->path, 'bytes');

    $this->artisan('ai-kit:prune-conversations', ['--days' => 7])->assertSuccessful();

    expect(ChatAttachment::query()->whereKey($boundAttachment->id)->exists())->toBeFalse();

    Storage::disk(ChatAttachment::DISK)->assertMissing($boundAttachment->path);
});

it('honors a custom retention window', function () {
    $conversationId = conversationIdleFor(3);

    $this->artisan('ai-kit:prune-conversations', ['--days' => 2])->assertSuccessful();

    expect(Conversation::query()->whereKey($conversationId)->exists())->toBeFalse();
});
