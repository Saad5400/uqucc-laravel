<?php

use App\Ai\Agents\StudentAssistant;
use App\Ai\Chat\CitationExtractor;
use App\Jobs\Ai\GenerateChatReply;
use App\Models\Ai\ChatAttachment;
use App\Models\Page;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Safety\KillSwitch;
use Saad\AiKit\Streaming\TurnBuffer;
use Saad\AiKit\Usage\UsageEvent;

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
        'participant_type' => \App\Ai\Chat\SessionOwner::class,
        'participant_id' => $sessionId,
        'title' => 'محادثة سابقة',
    ]);

    foreach ([['user', 'كم مكافأة الامتياز؟'], ['assistant', 'مكافأة الامتياز ألف ريال. (المصدر: /adwat/almkafa)']] as [$role, $content]) {
        ConversationMessage::query()->create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'participant_type' => \App\Ai\Chat\SessionOwner::class,
            'participant_id' => $sessionId,
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
        ->and($conversation->getAttribute('participant_id'))->toBe($sessionId)
        ->and(ConversationMessage::query()->where('conversation_id', $conversation->getKey())->count())->toBe(2);
});

it('records the turn on the spend ledger', function () {
    StudentAssistant::fake(['رد قصير.']);

    withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), ['message' => 'مرحبا'])
        ->assertOk()
        ->streamedContent();

    $usage = UsageEvent::query()->sole();

    expect($usage->feature)->toBe('assistant')
        ->and((float) ($usage->cost_usd ?? 0.0))->toBe(0.0);
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

/**
 * The student stream carries ai-kit v0.5.0's default `tool` and `reasoning`
 * events too — deliberately: the page turns them into "يبحث في صفحات
 * الدليل" chips and a collapsible thinking block. Neither carries tool
 * arguments or results, so nothing retrieved leaks to an anonymous visitor.
 */
it('brackets a tool call with running and done tool events, without arguments or results', function () {
    seedAssistantPage('مكافأة التفوق', 'ينال الطالب المتفوق مكافأة فصلية من الكلية');

    StudentAssistant::fake([
        new ToolCall('tc_1', 'search_content', ['query' => 'مكافأة']),
        'ينال المتفوق مكافأة فصلية.',
    ]);

    $content = withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), ['message' => 'كم المكافأة؟'])
        ->assertOk()
        ->streamedContent();

    preg_match_all("/^event: tool\ndata: (.+)$/m", $content, $matches);

    $events = array_map(fn (string $data): array => json_decode($data, true), $matches[1]);

    expect($events)->toHaveCount(2)
        ->and($events[0])->toBe(['id' => 'tc_1', 'name' => 'search_content', 'status' => 'running'])
        ->and($events[1]['status'])->toBe('done')
        ->and($events[1]['successful'])->toBeTrue()
        ->and(array_keys($events[1]))->toBe(['id', 'name', 'status', 'successful'])
        // The retrieved page body reached the model, never the wire.
        ->and($content)->not->toContain('ينال الطالب المتفوق مكافأة فصلية من الكلية');
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

it('rehydrates the typed message without its evidence wrapper, but with the files themselves', function () {
    StudentAssistant::fake(['رد.']);

    $sessionId = chatSessionId();

    $attachment = ChatAttachment::factory()->ready()->create([
        'session_id' => $sessionId,
        'original_filename' => 'transcript.pdf',
        'extracted_markdown' => 'المعدل التراكمي: 3.5',
    ]);

    $content = withChatSession($sessionId)
        ->post(route('ai.chat.send'), [
            'message' => 'كم معدلي؟',
            'attachment_ids' => [$attachment->id],
        ])
        ->streamedContent();

    $conversationId = sseEventData($content, 'done')['conversation_id'];

    $response = withChatSession($sessionId)
        ->getJson(route('ai.chat.show', $conversationId))
        ->assertOk()
        // The EXTRACTED TEXT stays stripped: it is evidence the model read, and
        // 20k characters of PDF is not something the visitor typed.
        ->assertJsonPath('messages.0.content', 'كم معدلي؟')
        // The FILE comes back beside it, which is what the bubble draws.
        ->assertJsonPath('messages.0.attachments.0.id', $attachment->id)
        ->assertJsonPath('messages.0.attachments.0.name', 'transcript.pdf')
        ->assertJsonPath('messages.0.attachments.0.mime', 'application/pdf')
        ->assertJsonPath('messages.0.attachments.0.url', route('ai.chat.attachments.show', $attachment->id));

    // Never the stored path, and never the extraction, on the wire.
    expect($response->json('messages.0.attachments.0'))->not->toHaveKeys(['path', 'disk', 'extracted_markdown'])
        ->and($response->json('messages.0'))->not->toHaveKey('attachments.0.path');
});

it('anchors a sent attachment to the user message it rode in on, not merely to the thread', function () {
    StudentAssistant::fake(['رد.']);

    $sessionId = chatSessionId();
    $first = ChatAttachment::factory()->ready()->create(['session_id' => $sessionId]);

    $content = withChatSession($sessionId)
        ->post(route('ai.chat.send'), ['message' => 'الرسالة الأولى', 'attachment_ids' => [$first->id]])
        ->streamedContent();

    $conversationId = sseEventData($content, 'done')['conversation_id'];

    // A SECOND turn in the same thread, with its own file. Anchoring by
    // conversation alone would put both files on both bubbles.
    $second = ChatAttachment::factory()->ready()->create(['session_id' => $sessionId]);

    withChatSession($sessionId)
        ->post(route('ai.chat.send'), [
            'message' => 'الرسالة الثانية',
            'conversation_id' => $conversationId,
            'attachment_ids' => [$second->id],
        ])
        ->streamedContent();

    $userMessages = ConversationMessage::query()
        ->where('conversation_id', $conversationId)
        ->where('role', 'user')
        ->orderBy('id')
        ->pluck('id');

    expect($first->refresh()->message_id)->toBe($userMessages[0])
        ->and($second->refresh()->message_id)->toBe($userMessages[1]);

    $thread = withChatSession($sessionId)->getJson(route('ai.chat.show', $conversationId))->assertOk();

    expect($thread->json('messages.0.attachments'))->toHaveCount(1)
        ->and($thread->json('messages.0.attachments.0.id'))->toBe($first->id)
        ->and($thread->json('messages.2.attachments'))->toHaveCount(1)
        ->and($thread->json('messages.2.attachments.0.id'))->toBe($second->id);
});

it('omits the attachments key entirely on a message that carried no files', function () {
    $sessionId = chatSessionId();
    $conversationId = createStoredConversation($sessionId);

    $response = withChatSession($sessionId)
        ->getJson(route('ai.chat.show', $conversationId))
        ->assertOk();

    // A text-only thread stays byte-identical to what it sent before.
    expect($response->json('messages.0'))->not->toHaveKey('attachments')
        ->and($response->json('messages.1'))->not->toHaveKey('attachments');
});

it('leaves an unanchored upload off every bubble', function () {
    $sessionId = chatSessionId();
    $conversationId = createStoredConversation($sessionId);

    // A turn that died before the store wrote its message: bound to the thread
    // but never to a message. No bubble may claim it.
    ChatAttachment::factory()->ready()->create([
        'session_id' => $sessionId,
        'conversation_id' => $conversationId,
        'message_id' => null,
    ]);

    $response = withChatSession($sessionId)
        ->getJson(route('ai.chat.show', $conversationId))
        ->assertOk();

    expect($response->json('messages.0'))->not->toHaveKey('attachments');
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

it("answers 503 while ai-kit's cache kill switch is engaged, all settings toggles on", function () {
    StudentAssistant::fake(['يجب ألا يظهر هذا الرد.']);

    app(KillSwitch::class)->engage(reason: 'حادثة تشغيلية');

    $this->postJson('/ai/chat', ['message' => 'مرحبا'])->assertServiceUnavailable();

    StudentAssistant::assertNeverPrompted();
});

it('refuses politely without calling the model once the daily budget is spent', function () {
    StudentAssistant::fake(['يجب ألا يظهر هذا الرد.']);

    app(BudgetGuard::class)->record(6.0);

    withChatSession(chatSessionId())
        ->postJson(route('ai.chat.send'), ['message' => 'مرحبا'])
        ->assertServiceUnavailable()
        ->assertJsonPath('message', __('ai-kit::safety.budget_exceeded'));

    StudentAssistant::assertNeverPrompted();

    expect(Conversation::query()->count())->toBe(0);
});

it('spending from yesterday does not block today', function () {
    StudentAssistant::fake(['رد.']);

    $this->travel(-1)->days();
    app(BudgetGuard::class)->record(6.0);
    $this->travelBack();

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

it('never streams a link the model invented, and keeps the real one', function () {
    config()->set('app.url', 'https://uqucc.sb.sa');

    Page::factory()->create(['slug' => '/alkhtt', 'title' => 'الخطة الدراسية']);

    StudentAssistant::fake([
        'راجع [الخطة الدراسية](https://uqucc.sb.sa/alkhtt) ثم [التخصصات](https://uqucc.sb.sa/wahm).',
    ]);

    $content = withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), ['message' => 'وش أفضل تخصص؟'])
        ->streamedContent();

    expect($content)->not->toContain('wahm')
        ->and($content)->toContain('/alkhtt')
        ->and($content)->toContain('التخصصات')
        ->and($content)->toContain('event: done');
});

it('strips an invented link from the stored thread when the panel rehydrates', function () {
    config()->set('app.url', 'https://uqucc.sb.sa');

    $sessionId = chatSessionId();
    $conversationId = createStoredConversation($sessionId);

    ConversationMessage::query()
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->update(['content' => 'انظر [التخصصات](https://uqucc.sb.sa/wahm).']);

    $messages = withChatSession($sessionId)
        ->getJson(route('ai.chat.show', $conversationId))
        ->json('messages');

    expect($messages[1]['content'])->toBe('انظر التخصصات.');
});

it('hands the model the majors evidence before it answers, without being asked', function () {
    StudentAssistant::fake(['ما فيه تخصص «أفضل».']);

    seedAssistantPage('دليل التخصصات', 'يدرس طالب تخصص علم البيانات مقررات الإحصاء');

    withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), ['message' => 'وش أفضل تخصص؟'])
        ->streamedContent();

    StudentAssistant::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'مصادر جاهزة من الدليل')
        && str_contains($prompt->prompt, 'مقررات الإحصاء')
        && str_ends_with($prompt->prompt, 'وش أفضل تخصص؟'));
});

it('leaves a turn that is not about majors free of injected evidence', function () {
    StudentAssistant::fake(['ألف ريال.']);

    withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), ['message' => 'كم مكافأة الامتياز؟'])
        ->streamedContent();

    StudentAssistant::assertPrompted(fn ($prompt) => $prompt->prompt === 'كم مكافأة الامتياز؟');
});

it('returns the visitor their own message, not the evidence wrapped around it', function () {
    StudentAssistant::fake(['ما فيه تخصص «أفضل».']);

    seedAssistantPage('دليل التخصصات', 'يدرس طالب تخصص علم البيانات مقررات الإحصاء');

    $sessionId = chatSessionId();

    $conversationId = sseEventData(
        withChatSession($sessionId)
            ->post(route('ai.chat.send'), ['message' => 'وش أفضل تخصص؟'])
            ->streamedContent(),
        'done',
    )['conversation_id'];

    $messages = withChatSession($sessionId)
        ->getJson(route('ai.chat.show', $conversationId))
        ->json('messages');

    expect($messages[0]['content'])->toBe('وش أفضل تخصص؟');
});

/*
|--------------------------------------------------------------------------
| Resumable turns (ai-kit TurnBuffer)
|--------------------------------------------------------------------------
|
| The reply is folded into a durable buffer by a queued job; the SSE
| endpoints only tail it. What these pin is the resume contract: the turn
| handle the client stores, the `id:` lines that are the cursor, replay from
| a cursor, ownership on both new endpoints, and cancellation.
|
*/

/** The reply as the client would assemble it, across however many deltas it took. */
function sseDeltaText(string $content): string
{
    preg_match_all("/^event: delta\ndata: (.+)$/m", $content, $matches);

    return implode('', array_map(
        fn (string $data): string => (string) (json_decode($data, true)['text'] ?? ''),
        $matches[1],
    ));
}

/** Every SSE frame in the content, as [seq, event] pairs. */
function sseFrames(string $content): array
{
    preg_match_all("/^id: (\d+)\nevent: (\S+)$/m", $content, $matches, PREG_SET_ORDER);

    return array_map(fn (array $match): array => [(int) $match[1], $match[2]], $matches);
}

it('opens the stream with a turn handle and numbers every frame for resuming', function () {
    StudentAssistant::fake(['أهلاً بك.']);

    $content = withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), ['message' => 'مرحبا'])
        ->assertOk()
        ->streamedContent();

    $frames = sseFrames($content);
    $turn = sseEventData($content, 'turn');

    // The handle is the FIRST frame — a client that drops immediately still
    // knows which turn to reconnect to.
    expect($frames[0][1])->toBe('turn')
        ->and($turn['id'])->not->toBeEmpty()
        ->and(app(TurnBuffer::class)->get($turn['id']))->not->toBeNull();

    // Sequence numbers are dense and ascending: they ARE the cursor.
    expect(array_column($frames, 0))->toBe(range(1, count($frames)))
        ->and(collect($frames)->pluck(1))->toContain('delta', 'done');
});

it('replays only the frames after the cursor a client resumes at', function () {
    StudentAssistant::fake(['أهلاً بك.']);

    $sessionId = chatSessionId();

    $first = withChatSession($sessionId)
        ->post(route('ai.chat.send'), ['message' => 'مرحبا'])
        ->streamedContent();

    $turnId = sseEventData($first, 'turn')['id'];
    $frames = sseFrames($first);
    $lastSeq = end($frames)[0];

    // Resuming where the first read stopped yields nothing new...
    $drained = withChatSession($sessionId)
        ->get(route('ai.chat.stream', ['turn' => $turnId, 'cursor' => $lastSeq]))
        ->assertOk()
        ->streamedContent();

    expect(sseFrames($drained))->toBeEmpty();

    // ...while resuming from the handle replays the reply itself, and only it.
    $resumed = withChatSession($sessionId)
        ->get(route('ai.chat.stream', ['turn' => $turnId, 'cursor' => 1]))
        ->assertOk()
        ->streamedContent();

    expect($resumed)->not->toContain('event: turn')
        ->and(sseDeltaText($resumed))->toBe('أهلاً بك.')
        ->and(sseEventData($resumed, 'done'))->not->toBeNull();
});

it('resumes from Last-Event-ID when the client sends no cursor', function () {
    StudentAssistant::fake(['أهلاً بك.']);

    $sessionId = chatSessionId();

    $first = withChatSession($sessionId)
        ->post(route('ai.chat.send'), ['message' => 'مرحبا'])
        ->streamedContent();

    $turnId = sseEventData($first, 'turn')['id'];
    $frames = sseFrames($first);
    $lastSeq = end($frames)[0];

    $resumed = withChatSession($sessionId)
        ->withHeader('Last-Event-ID', (string) $lastSeq)
        ->get(route('ai.chat.stream', ['turn' => $turnId]))
        ->assertOk()
        ->streamedContent();

    expect(sseFrames($resumed))->toBeEmpty();
});

it('answers 404 on a turn belonging to another session, and on an unknown one', function () {
    StudentAssistant::fake(['أهلاً بك.']);

    $turnId = sseEventData(
        withChatSession(chatSessionId())->post(route('ai.chat.send'), ['message' => 'مرحبا'])->streamedContent(),
        'turn',
    )['id'];

    withChatSession(chatSessionId())->get(route('ai.chat.stream', ['turn' => $turnId]))->assertNotFound();
    withChatSession(chatSessionId())->post(route('ai.chat.cancel', ['turn' => $turnId]))->assertNotFound();
    withChatSession(chatSessionId())->get(route('ai.chat.stream', ['turn' => (string) Str::uuid7()]))->assertNotFound();
});

it('flags a turn cancelled for its own session', function () {
    StudentAssistant::fake(['أهلاً بك.']);

    $sessionId = chatSessionId();

    $turnId = sseEventData(
        withChatSession($sessionId)->post(route('ai.chat.send'), ['message' => 'مرحبا'])->streamedContent(),
        'turn',
    )['id'];

    expect(app(TurnBuffer::class)->isCancelled($turnId))->toBeFalse();

    withChatSession($sessionId)
        ->post(route('ai.chat.cancel', ['turn' => $turnId]))
        ->assertOk()
        ->assertJson(['cancelled' => true]);

    expect(app(TurnBuffer::class)->isCancelled($turnId))->toBeTrue();
});

it('fails a queued turn without calling the model when the kill switch engaged after it was queued', function () {
    StudentAssistant::fake(['لن يصل هذا الرد أبداً.']);

    $buffer = app(TurnBuffer::class);
    $turnId = (string) Str::uuid7();

    $buffer->start($turnId, ['session_id' => 'session-under-test']);

    // Engaged AFTER the turn entered the queue: the job is where the money
    // would actually be spent, so that is where the switch has to bite.
    app(KillSwitch::class)->engage();

    (new GenerateChatReply(turnId: $turnId, sessionId: 'session-under-test', prompt: 'مرحبا'))
        ->handle($buffer, app(KillSwitch::class), app(CitationExtractor::class));

    $record = $buffer->get($turnId);

    expect($record['status'])->toBe('failed')
        ->and(collect($record['events'])->pluck('event')->all())->toBe(['error'])
        ->and(Conversation::query()->count())->toBe(0);
});

it('lets a dropped client resume and stop even once the burst limiter is exhausted', function () {
    StudentAssistant::fake(fn () => 'رد.');

    $sessionId = chatSessionId();

    $turnId = sseEventData(
        withChatSession($sessionId)->post(route('ai.chat.send'), ['message' => 'مرحبا'])->streamedContent(),
        'turn',
    )['id'];

    // Spend the rest of the 5-per-minute burst budget, then prove it really is
    // spent: a sixth NEW turn is refused.
    foreach (range(2, 5) as $index) {
        withChatSession($sessionId)
            ->post(route('ai.chat.send'), ['message' => "رسالة {$index}"])
            ->streamedContent();
    }

    withChatSession($sessionId)
        ->postJson(route('ai.chat.send'), ['message' => 'السادسة'])
        ->assertTooManyRequests();

    // The reconnect ladder still gets through. One long turn can need several
    // reconnects (the kit's tail closes at its ceiling and the client re-issues
    // its cursor), so counting them against the same five would 429 a client
    // back into exactly the lost reply resumability exists to prevent — for a
    // turn already generated and charged.
    withChatSession($sessionId)
        ->get(route('ai.chat.stream', ['turn' => $turnId, 'cursor' => 1]))
        ->assertOk();

    // And so does the stop, which is the only way to end a queued turn early
    // now that hanging up does not.
    withChatSession($sessionId)
        ->post(route('ai.chat.cancel', ['turn' => $turnId]))
        ->assertOk();
});

/*
 * A turn must not land on `default` (one worker, no --timeout, so turns
 * serialize behind each other and are killed at 60s) nor on `ai` (multi-minute
 * corpus extraction and ingestion, which an interactive reply would wait
 * behind). nixpacks.toml's `worker-ai-chat` is the other half of this pairing;
 * the queue NAME is the whole contract between config and worker topology, so
 * it is worth a test on each surface.
 *
 * The streamed body is deliberately NOT rendered: with the queue faked the turn
 * never completes, so tailing its buffer would block until the kit's stream
 * deadline. Only the dispatch is under test here.
 */
it('dispatches student assistant turns onto the dedicated ai-chat queue', function () {
    Queue::fake();

    withChatSession(chatSessionId())
        ->post(route('ai.chat.send'), ['message' => 'مرحبا'])
        ->assertOk();

    Queue::assertPushedOn('ai-chat', GenerateChatReply::class);
});
