<?php

use App\Ai\Agents\StudentAssistant;
use App\Models\Ai\ChatAttachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Create a conversation (with one message) whose activity timestamp is
 * backdated without the model touching it again.
 */
function conversationIdleFor(int $days): string
{
    $conversationId = (string) Str::uuid7();

    Conversation::query()->create([
        'id' => $conversationId,
        'user_id' => Str::random(40),
        'title' => 'محادثة',
    ]);

    ConversationMessage::query()->create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'user_id' => null,
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

    $this->artisan('ai:prune-conversations')->assertSuccessful();

    expect(Conversation::query()->whereKey($oldConversationId)->exists())->toBeFalse()
        ->and(ConversationMessage::query()->where('conversation_id', $oldConversationId)->exists())->toBeFalse()
        ->and(Conversation::query()->whereKey($freshConversationId)->exists())->toBeTrue()
        ->and(ConversationMessage::query()->where('conversation_id', $freshConversationId)->exists())->toBeTrue()
        ->and(ChatAttachment::query()->whereKey($oldAttachment->id)->exists())->toBeFalse()
        ->and(ChatAttachment::query()->whereKey($freshAttachment->id)->exists())->toBeTrue();

    Storage::disk(ChatAttachment::DISK)->assertMissing($oldAttachment->path);
    Storage::disk(ChatAttachment::DISK)->assertExists($freshAttachment->path);
});

it('honors a custom retention window', function () {
    $conversationId = conversationIdleFor(3);

    $this->artisan('ai:prune-conversations', ['--days' => 2])->assertSuccessful();

    expect(Conversation::query()->whereKey($conversationId)->exists())->toBeFalse();
});
