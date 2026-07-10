<?php

use App\Ai\Agents\StudentAssistant;
use App\Models\Ai\AiUsage;
use App\Models\Ai\ChatAttachment;
use App\Models\Page;
use App\Settings\AiSettings;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\ToolCall;

beforeEach(function () {
    config()->set('ai.embeddings.driver', 'fake');
    config()->set('ai.embeddings.dimensions', 64);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->assistant_enabled = true;
    $settings->search_enabled = true;
    $settings->daily_budget_usd = 5.0;
    $settings->per_session_rate_limit = 20;
    $settings->save();
});

/**
 * Pin the visitor's session id: the framework encrypts default cookies for
 * the request, so StartSession adopts this id exactly — which is what keys
 * conversation ownership and the rate limiters.
 */
function withChatSession(string $sessionId)
{
    // withCredentials(): json helpers (getJson/postJson) only forward default
    // cookies when credentials are enabled.
    return test()
        ->withCredentials()
        ->withCookie((string) config('session.cookie'), $sessionId);
}

function chatSessionId(): string
{
    return Str::random(40);
}

/**
 * Parse one named SSE event's data payload out of a streamed chat response.
 *
 * @return array<string, mixed>|null
 */
function sseEventData(string $content, string $event): ?array
{
    if (preg_match('/^event: '.preg_quote($event, '/')."\ndata: (.+)$/m", $content, $matches) !== 1) {
        return null;
    }

    return json_decode($matches[1], true);
}

function seedAssistantPage(string $title, string $body): Page
{
    return Page::factory()->create([
        'title' => $title,
        'html_content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $body]]],
            ],
        ],
    ]);
}

function createStoredConversation(string $sessionId): string
{
    $conversationId = (string) Str::uuid7();

    Conversation::query()->create([
        'id' => $conversationId,
        'user_id' => $sessionId,
        'title' => 'محادثة سابقة',
    ]);

    foreach ([['user', 'كم مكافأة الامتياز؟'], ['assistant', 'مكافأة الامتياز ألف ريال. (المصدر: /adwat/almkafa)']] as [$role, $content]) {
        ConversationMessage::query()->create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => $sessionId,
            'agent' => StudentAssistant::class,
            'role' => $role,
            'content' => $content,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
        ]);
    }

    return $conversationId;
}

it('streams delta and done events and persists the conversation for the session', function () {
    StudentAssistant::fake(['أهلاً! كيف أساعدك اليوم؟']);

    $sessionId = chatSessionId();

    $response = withChatSession($sessionId)->post(route('ai.chat.send'), ['message' => 'مرحبا']);

    $response->assertOk();

    expect((string) $response->baseResponse->headers->get('Content-Type'))->toContain('text/event-stream');

    $content = $response->streamedContent();

    expect($content)->toContain('event: delta')
        ->and($content)->toContain('أهلاً!')
        ->and($content)->toContain('event: done');

    $done = sseEventData($content, 'done');
    $conversation = Conversation::query()->sole();

    expect($done['conversation_id'])->toBe($conversation->getKey())
        ->and($done['message_id'])->not->toBeNull()
        ->and($conversation->getAttribute('user_id'))->toBe($sessionId)
        ->and(ConversationMessage::query()->where('conversation_id', $conversation->getKey())->count())->toBe(2);
});

it('records the turn on the spend ledger', function () {
    StudentAssistant::fake(['رد قصير.']);

    withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), ['message' => 'مرحبا'])
        ->assertOk()
        ->streamedContent();

    $usage = AiUsage::query()->sole();

    expect($usage->feature)->toBe('assistant')
        ->and($usage->cost)->toBe(0.0);
});

it('continues an owned conversation instead of starting a new one', function () {
    StudentAssistant::fake(['الرد الأول.', 'الرد الثاني.']);

    $sessionId = chatSessionId();

    $first = withChatSession($sessionId)->post(route('ai.chat.send'), ['message' => 'سؤال أول']);
    $conversationId = sseEventData($first->streamedContent(), 'done')['conversation_id'];

    withChatSession($sessionId)
        ->post(route('ai.chat.send'), ['message' => 'سؤال ثانٍ', 'conversation_id' => $conversationId])
        ->assertOk()
        ->streamedContent();

    expect(Conversation::query()->count())->toBe(1)
        ->and(ConversationMessage::query()->where('conversation_id', $conversationId)->count())->toBe(4);
});

it('starts a fresh thread when the conversation id belongs to another session', function () {
    StudentAssistant::fake(['رد.', 'رد آخر.']);

    $first = withChatSession(chatSessionId())->post(route('ai.chat.send'), ['message' => 'سؤال']);
    $foreignConversationId = sseEventData($first->streamedContent(), 'done')['conversation_id'];

    withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), ['message' => 'سؤال دخيل', 'conversation_id' => $foreignConversationId])
        ->assertOk()
        ->streamedContent();

    expect(Conversation::query()->count())->toBe(2)
        ->and(ConversationMessage::query()->where('conversation_id', $foreignConversationId)->count())->toBe(2);
});

it('emits citations for content the tools consulted', function () {
    $page = seedAssistantPage('مكافأة التفوق', 'ينال الطالب المتفوق مكافأة فصلية من الكلية');

    StudentAssistant::fake([
        new ToolCall('tc_1', 'search_content', ['query' => 'مكافأة']),
        'ينال المتفوق مكافأة فصلية. (المصدر: '.$page->slug.')',
    ]);

    $content = withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), ['message' => 'كم المكافأة؟'])
        ->assertOk()
        ->streamedContent();

    $citations = sseEventData($content, 'citations');

    expect($citations)->not->toBeNull()
        ->and($citations['items'][0]['title'])->toBe('مكافأة التفوق')
        ->and($citations['items'][0]['slug'])->toBe($page->slug)
        ->and($citations['items'][0])->toHaveKeys(['title', 'slug', 'heading']);
});

it('emits a citation for a page read in full via get_page despite the freshness-date footer line', function () {
    $page = seedAssistantPage('مكافأة التفوق', 'ينال الطالب المتفوق مكافأة فصلية من الكلية');

    StudentAssistant::fake([
        new ToolCall('tc_1', 'get_page', ['slug' => $page->slug]),
        'ينال المتفوق مكافأة فصلية.',
    ]);

    $content = withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), ['message' => 'كم المكافأة؟'])
        ->assertOk()
        ->streamedContent();

    $citations = sseEventData($content, 'citations');

    expect($citations)->not->toBeNull()
        ->and($citations['items'][0]['title'])->toBe('مكافأة التفوق')
        ->and($citations['items'][0]['slug'])->toBe($page->slug);
});

it('injects a ready attachment extraction as context for the turn', function () {
    StudentAssistant::fake(['معدلك التراكمي 3.5.']);

    $sessionId = chatSessionId();

    $attachment = ChatAttachment::factory()->ready()->create([
        'session_id' => $sessionId,
        'extracted_markdown' => "## السجل الأكاديمي\nالمعدل التراكمي: 3.5",
    ]);

    $content = withChatSession($sessionId)
        ->post(route('ai.chat.send'), [
            'message' => 'كم معدلي؟',
            'attachment_ids' => [$attachment->id],
        ])
        ->assertOk()
        ->streamedContent();

    StudentAssistant::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'المعدل التراكمي: 3.5')
        && str_contains($prompt->prompt, 'كم معدلي؟'));

    // The attachment is bound to the conversation the turn landed in.
    $conversationId = sseEventData($content, 'done')['conversation_id'];

    expect($attachment->refresh()->conversation_id)->toBe($conversationId);
});

it('ignores attachments owned by another session', function () {
    StudentAssistant::fake(['رد.']);

    $attachment = ChatAttachment::factory()->ready()->create([
        'session_id' => chatSessionId(),
        'extracted_markdown' => 'نص سري لجلسة أخرى',
    ]);

    withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), [
            'message' => 'اقرأ الملف',
            'attachment_ids' => [$attachment->id],
        ])
        ->assertOk()
        ->streamedContent();

    StudentAssistant::assertPrompted(fn ($prompt) => ! str_contains($prompt->prompt, 'نص سري لجلسة أخرى'));
});

it('returns the stored thread to its owning session with the contract shape', function () {
    $sessionId = chatSessionId();
    $conversationId = createStoredConversation($sessionId);

    withChatSession($sessionId)
        ->getJson(route('ai.chat.show', $conversationId))
        ->assertOk()
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.role', 'user')
        ->assertJsonPath('messages.0.content', 'كم مكافأة الامتياز؟')
        ->assertJsonPath('messages.1.role', 'assistant')
        ->assertJsonStructure(['messages' => [['role', 'content', 'citations', 'created_at']]]);
});

it('hides the original typed message behind attachment wrapping when rehydrating', function () {
    StudentAssistant::fake(['رد.']);

    $sessionId = chatSessionId();

    $attachment = ChatAttachment::factory()->ready()->create(['session_id' => $sessionId]);

    $content = withChatSession($sessionId)
        ->post(route('ai.chat.send'), [
            'message' => 'كم معدلي؟',
            'attachment_ids' => [$attachment->id],
        ])
        ->streamedContent();

    $conversationId = sseEventData($content, 'done')['conversation_id'];

    withChatSession($sessionId)
        ->getJson(route('ai.chat.show', $conversationId))
        ->assertOk()
        ->assertJsonPath('messages.0.content', 'كم معدلي؟');
});

it('answers 404 when another session requests the thread', function () {
    $conversationId = createStoredConversation(chatSessionId());

    withChatSession(chatSessionId())
        ->getJson(route('ai.chat.show', $conversationId))
        ->assertNotFound();
});

it('answers 503 on every endpoint while the assistant toggle is off', function (string $method, string $uri, array $payload) {
    $settings = app(AiSettings::class);
    $settings->assistant_enabled = false;
    $settings->save();

    $response = $method === 'get'
        ? $this->getJson($uri)
        : $this->postJson($uri, $payload);

    $response->assertServiceUnavailable()
        ->assertJsonStructure(['message']);
})->with([
    'send' => ['post', '/ai/chat', fn (): array => ['message' => 'مرحبا']],
    'attachments' => ['post', '/ai/chat/attachments', fn (): array => ['file' => Illuminate\Http\UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')]],
    'show' => ['get', '/ai/chat/0197fa00-0000-7000-8000-000000000000', fn (): array => []],
]);

it('answers 503 on every endpoint while the master ai kill switch is off', function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->save();

    $this->postJson('/ai/chat', ['message' => 'مرحبا'])->assertServiceUnavailable();
});

it('refuses politely without calling the model once the daily budget is spent', function () {
    StudentAssistant::fake(['يجب ألا يظهر هذا الرد.']);

    AiUsage::factory()->create(['cost' => 6.0]);

    withChatSession(chatSessionId())
        ->postJson(route('ai.chat.send'), ['message' => 'مرحبا'])
        ->assertServiceUnavailable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'غير متاح اليوم'));

    StudentAssistant::assertNeverPrompted();

    expect(Conversation::query()->count())->toBe(0);
});

it('spending from yesterday does not block today', function () {
    StudentAssistant::fake(['رد.']);

    AiUsage::factory()->create(['cost' => 6.0, 'created_at' => now()->subDay()]);

    withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), ['message' => 'مرحبا'])
        ->assertOk();
});

it('rate limits the chat burst per session after 5 requests per minute', function () {
    StudentAssistant::fake(fn () => 'رد.');

    $sessionId = chatSessionId();

    foreach (range(1, 5) as $i) {
        withChatSession($sessionId)
            ->post(route('ai.chat.send'), ['message' => "رسالة {$i}"])
            ->assertOk()
            ->streamedContent();
    }

    withChatSession($sessionId)
        ->postJson(route('ai.chat.send'), ['message' => 'السادسة'])
        ->assertTooManyRequests();
});

it('enforces the operator daily per-session quota with an arabic message', function () {
    StudentAssistant::fake(fn () => 'رد.');

    $settings = app(AiSettings::class);
    $settings->per_session_rate_limit = 2;
    $settings->save();

    $sessionId = chatSessionId();

    foreach (range(1, 2) as $i) {
        withChatSession($sessionId)
            ->post(route('ai.chat.send'), ['message' => "رسالة {$i}"])
            ->assertOk()
            ->streamedContent();
    }

    withChatSession($sessionId)
        ->postJson(route('ai.chat.send'), ['message' => 'الثالثة'])
        ->assertTooManyRequests()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'الحد اليومي'));

    // Another session is unaffected by the first session's quota.
    withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), ['message' => 'جلسة أخرى'])
        ->assertOk();
});

it('rejects invalid chat payloads', function (array $payload, string $field) {
    StudentAssistant::fake(['رد.']);

    $this->postJson(route('ai.chat.send'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    StudentAssistant::assertNeverPrompted();
})->with([
    'missing message' => [[], 'message'],
    'empty message' => [['message' => ''], 'message'],
    'message too long' => [['message' => str_repeat('ب', 2001)], 'message'],
    'attachment_ids not an array' => [['message' => 'مرحبا', 'attachment_ids' => 'abc'], 'attachment_ids'],
    'attachment id not a ulid' => [['message' => 'مرحبا', 'attachment_ids' => ['not-a-ulid']], 'attachment_ids.0'],
    'too many attachments' => [['message' => 'مرحبا', 'attachment_ids' => array_fill(0, 6, '01JX0000000000000000000000')], 'attachment_ids'],
]);
