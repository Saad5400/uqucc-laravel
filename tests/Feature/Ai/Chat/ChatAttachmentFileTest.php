<?php

use App\Ai\Chat\SessionOwner;
use App\Models\Ai\ChatAttachment;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;

/**
 * The download door onto a sent chat attachment: the visitor opening a file
 * they themselves attached, from the chip on their own bubble.
 *
 * The whole surface is anonymous, so the only identity is the session that
 * stored the row — and every refusal is a 404, never a 403, so the ULID space
 * cannot be walked as a census oracle.
 */
beforeEach(function () {
    Storage::fake(ChatAttachment::DISK);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->assistant_enabled = true;
    $settings->save();
});

/** An attachment whose bytes really exist on the fake disk. */
function downloadableAttachment(array $attributes = []): ChatAttachment
{
    $attachment = ChatAttachment::factory()->ready()->create($attributes);

    Storage::disk($attachment->disk)->put($attachment->path, 'PDF-BYTES');

    return $attachment;
}

/** A stored thread owned by the given session, as laravel/ai records it. */
function attachmentConversation(string $sessionId): string
{
    $conversationId = (string) Str::uuid7();

    Conversation::query()->create([
        'id' => $conversationId,
        'participant_type' => SessionOwner::class,
        'participant_id' => $sessionId,
        'title' => 'محادثة',
    ]);

    return $conversationId;
}

it('streams a visitor their own attachment with the stored type and nosniff', function () {
    $sessionId = chatSessionId();
    $conversationId = attachmentConversation($sessionId);

    $attachment = downloadableAttachment([
        'session_id' => $sessionId,
        'conversation_id' => $conversationId,
        'message_id' => (string) Str::uuid7(),
        'original_filename' => 'transcript.pdf',
    ]);

    $response = withChatSession($sessionId)
        ->get(route('ai.chat.attachments.show', $attachment->id))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        // The declared type must hold: an innocent extension can never be
        // sniffed into an active document on our own origin.
        ->assertHeader('x-content-type-options', 'nosniff');

    expect($response->streamedContent())->toBe('PDF-BYTES')
        // A PDF is in the inline-safe set, so it opens rather than downloads.
        ->and($response->headers->get('content-disposition'))->toStartWith('inline');

    // A visitor's private upload is never stored in a shared cache.
    expect($response->headers->get('cache-control'))->toContain('private')
        ->and($response->headers->get('cache-control'))->toContain('no-store');
});

it('forces anything outside the inline-safe set to download instead of render', function () {
    $sessionId = chatSessionId();

    // A file whose recorded mime is active content. The upload validator does
    // not accept this today; the route refuses to render it anyway, so the
    // posture survives the validator ever widening.
    $attachment = downloadableAttachment([
        'session_id' => $sessionId,
        'mime' => 'image/svg+xml',
        'original_filename' => 'payload.svg',
    ]);

    $response = withChatSession($sessionId)
        ->get(route('ai.chat.attachments.show', $attachment->id))
        ->assertOk()
        ->assertHeader('x-content-type-options', 'nosniff');

    expect($response->headers->get('content-disposition'))->toStartWith('attachment');
});

it('answers 404 — never 403 — for another session\'s attachment', function () {
    $attachment = downloadableAttachment(['session_id' => chatSessionId()]);

    // A 403 here would confirm the id exists, which is what makes the id space
    // enumerable. A stranger's id must be indistinguishable from a made-up one.
    withChatSession(chatSessionId())
        ->get(route('ai.chat.attachments.show', $attachment->id))
        ->assertNotFound();
});

it('answers the same 404 for an id that never existed', function () {
    withChatSession(chatSessionId())
        ->get(route('ai.chat.attachments.show', (string) Str::ulid()))
        ->assertNotFound();
});

it('refuses an attachment filed under a thread the session no longer owns', function () {
    $sessionId = chatSessionId();

    // The row says this session uploaded it, but the THREAD belongs to someone
    // else. The two can only disagree through a bug — the second gate is what
    // stops that bug from becoming a disclosure.
    $attachment = downloadableAttachment([
        'session_id' => $sessionId,
        'conversation_id' => attachmentConversation(chatSessionId()),
        'message_id' => (string) Str::uuid7(),
    ]);

    withChatSession($sessionId)
        ->get(route('ai.chat.attachments.show', $attachment->id))
        ->assertNotFound();
});

it('still serves an unanchored upload to its own uploader', function () {
    $sessionId = chatSessionId();

    // A turn that never stored a message leaves the row unanchored. It is still
    // this session's own file, and `session_id` already proved it — hiding a
    // visitor's own upload to enforce an anchor that was never written is not
    // security.
    $attachment = downloadableAttachment([
        'session_id' => $sessionId,
        'conversation_id' => null,
        'message_id' => null,
    ]);

    withChatSession($sessionId)
        ->get(route('ai.chat.attachments.show', $attachment->id))
        ->assertOk();
});

it('answers 404 when the row outlived its file on the disk', function () {
    $sessionId = chatSessionId();

    // The retention sweep deletes rows and files together, so this is a routine
    // edge rather than a server fault — a 500 would page someone for nothing.
    $attachment = ChatAttachment::factory()->ready()->create(['session_id' => $sessionId]);

    withChatSession($sessionId)
        ->get(route('ai.chat.attachments.show', $attachment->id))
        ->assertNotFound();
});

it('keeps opening attachments once the chat burst limiter is spent', function () {
    $sessionId = chatSessionId();

    $attachment = downloadableAttachment(['session_id' => $sessionId]);

    // `throttle:ai-chat` is 5 turns a minute. One rehydrated thread can offer
    // more chips than that, and opening a file spends no model call, no quota
    // slot and no budget — so the route sits outside the limiter, exactly like
    // the resume/cancel pair.
    for ($i = 0; $i < 6; $i++) {
        withChatSession($sessionId)->post(route('ai.chat.send'), ['message' => 'مرحباً']);
    }

    withChatSession($sessionId)
        ->post(route('ai.chat.send'), ['message' => 'مرحباً'])
        ->assertStatus(429);

    withChatSession($sessionId)
        ->get(route('ai.chat.attachments.show', $attachment->id))
        ->assertOk();
});
