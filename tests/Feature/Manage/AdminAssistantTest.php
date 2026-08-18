<?php

use App\Ai\Admin\Actions\AssistantActionTool;
use App\Ai\Admin\Actions\Settings\GetSettingsAction;
use App\Ai\Admin\AdminAssistant;
use App\Ai\Admin\SettingsRegistry;
use App\Models\Page;
use App\Models\PrivateTutor\PrivateTutor;
use App\Models\User;
use App\Settings\AiSettings;
use Database\Factories\PrivateTutor\PrivateTutorFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request as ToolRequest;
use Saad\AiKit\Approvals\Classified\StoredApprovals;
use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Safety\KillSwitch;
use Saad\AiKit\Usage\UsageEvent;
use Tests\Support\OpenRouterSse;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->admin_assistant_enabled = true;
    $settings->daily_budget_usd = 5.0;
    $settings->save();
});

/**
 * Parse one named SSE event's data payload out of a streamed chat response.
 *
 * @return array<string, mixed>|null
 */
function adminSseEventData(string $content, string $event): ?array
{
    if (preg_match('/^event: '.preg_quote($event, '/')."\ndata: (.+)$/m", $content, $matches) !== 1) {
        return null;
    }

    return json_decode($matches[1], true);
}

/**
 * Every payload streamed under one event name, in arrival order — `tool`
 * and `reasoning` both arrive repeatedly per turn.
 *
 * @return list<array<string, mixed>>
 */
function adminSseEvents(string $content, string $event): array
{
    preg_match_all('/^event: '.preg_quote($event, '/')."\ndata: (.+)$/m", $content, $matches);

    return array_map(
        fn (string $data): array => json_decode($data, true),
        $matches[1],
    );
}

function disableAdminAssistant(bool $masterToo = false): void
{
    $settings = app(AiSettings::class);
    $settings->admin_assistant_enabled = false;

    if ($masterToo) {
        $settings->ai_enabled = false;
    }

    $settings->save();
}

function createAdminConversation(User $admin): string
{
    $conversationId = (string) Str::uuid7();

    Conversation::query()->create([
        'id' => $conversationId,
        'participant_type' => \App\Ai\Admin\AdminOwner::class,
        'participant_id' => 'admin:'.$admin->getKey(),
        'title' => 'محادثة إدارية',
    ]);

    foreach ([['user', 'أخفِ الصفحة.'], ['assistant', 'تم.']] as [$role, $content]) {
        ConversationMessage::query()->create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'participant_type' => \App\Ai\Admin\AdminOwner::class,
            'participant_id' => 'admin:'.$admin->getKey(),
            'agent' => AdminAssistant::class,
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

/**
 * Fake the OpenRouter HTTP layer with a sequence of SSE bodies — used by
 * the pause/resume tests, because the vendor executes resumed tools only
 * against a NON-faked agent gateway: the agent-level fake deliberately
 * disables the Approvable resume, so those tests run the REAL generation
 * loop and fake only the wire.
 *
 * @param  list<string>  $bodies
 */
function fakeOpenRouter(array $bodies): void
{
    config()->set('ai.providers.openrouter.key', 'test-key');

    $sequence = Http::sequence();

    foreach ($bodies as $body) {
        $sequence->push($body, 200, ['Content-Type' => 'text/event-stream']);
    }

    Http::fake(['*/chat/completions' => $sequence]);
}

/**
 * Run one send whose turn pauses (both fake styles produce a genuine pause:
 * the fake feeds the REAL generation loop, which stores approval_state and
 * emits the cards), returning [conversationId, streamed SSE content].
 */
function pauseTurnOn(User $admin, ToolCall $toolCall, string $message = 'نفّذ التغيير'): array
{
    $content = test()->actingAs($admin)
        ->post(route('manage.assistant.send'), ['message' => $message])
        ->assertOk()
        ->streamedContent();

    $conversationId = adminSseEventData($content, 'done')['conversation_id'] ?? null;

    expect($conversationId)->toBeString();

    return [$conversationId, $content];
}

describe('authorization and gating', function () {
    it('redirects guests to the login page', function () {
        $this->get(route('manage.assistant.index'))->assertRedirect(route('manage.login'));
    });

    it('returns 403 for users without a panel role', function () {
        $this->actingAs(User::factory()->create());

        $this->get(route('manage.assistant.index'))->assertForbidden();
    });

    it('renders the chat page enabled for a panel user', function () {
        $this->actingAs($this->admin)
            ->get(route('manage.assistant.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('manage/assistant/Index')
                ->where('assistant.enabled', true)
                ->where('assistant.disabledReason', null));
    });

    it('renders the page disabled with a reason while the feature toggle is off', function () {
        disableAdminAssistant();

        $this->actingAs($this->admin)
            ->get(route('manage.assistant.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('manage/assistant/Index')
                ->where('assistant.enabled', false)
                ->where('assistant.disabledReason', fn (string $reason) => str_contains($reason, 'المساعد الإداري')));
    });

    it('answers 503 on send while the feature toggle is off, without calling the model', function () {
        AdminAssistant::fake(['يجب ألا يظهر هذا الرد.']);

        disableAdminAssistant();

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.send'), ['message' => 'مرحبا'])
            ->assertServiceUnavailable()
            ->assertJsonStructure(['message']);

        AdminAssistant::assertNeverPrompted();
    });

    it('answers 503 on send while the master ai kill switch is off', function () {
        AdminAssistant::fake(['يجب ألا يظهر هذا الرد.']);

        disableAdminAssistant(masterToo: true);

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.send'), ['message' => 'مرحبا'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'الذكاء الاصطناعي'));

        AdminAssistant::assertNeverPrompted();
    });

    it("answers 503 on send while ai-kit's cache kill switch is engaged, both settings toggles on", function () {
        AdminAssistant::fake(['يجب ألا يظهر هذا الرد.']);

        app(KillSwitch::class)->engage('admin_assistant', 'حادثة تشغيلية');

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.send'), ['message' => 'مرحبا'])
            ->assertServiceUnavailable();

        AdminAssistant::assertNeverPrompted();
    });

    it('refuses politely without calling the model once the daily budget is spent', function () {
        AdminAssistant::fake(['يجب ألا يظهر هذا الرد.']);

        app(BudgetGuard::class)->record(6.0);

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.send'), ['message' => 'مرحبا'])
            ->assertServiceUnavailable();

        AdminAssistant::assertNeverPrompted();
    });

    it('answers 503 on decide while the feature is off, applying nothing', function () {
        $page = Page::factory()->create(['title' => 'قديم']);

        AdminAssistant::fake([
            new ToolCall('tc_1', 'manage_page_structure', ['action' => 'rename', 'page_id' => $page->id, 'title' => 'جديد']),
            'نفّذت.',
        ]);

        [$conversationId] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'manage_page_structure', []));

        disableAdminAssistant();

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.decide', $conversationId), ['decisions' => ['tc_1' => 'approve']])
            ->assertServiceUnavailable();

        expect($page->refresh()->title)->toBe('قديم');
    });

    it('rejects an over-long message', function () {
        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.send'), ['message' => str_repeat('م', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
    });
});

describe('chat streaming', function () {
    it('streams delta and done events and persists the conversation for the admin owner', function () {
        AdminAssistant::fake(['أهلاً! كيف أساعدك في إدارة الموقع؟']);

        $response = $this->actingAs($this->admin)->post(route('manage.assistant.send'), ['message' => 'مرحبا']);

        $response->assertOk();

        expect((string) $response->baseResponse->headers->get('Content-Type'))->toContain('text/event-stream');

        $content = $response->streamedContent();

        expect($content)->toContain('event: delta')
            ->and($content)->toContain('event: done');

        $conversation = Conversation::query()->sole();

        expect($conversation->getAttribute('participant_id'))->toBe('admin:'.$this->admin->id)
            ->and(adminSseEventData($content, 'done')['conversation_id'])->toBe($conversation->getKey());
    });

    it('records the turn on the spend ledger under the admin_assistant feature', function () {
        AdminAssistant::fake(['رد قصير.']);

        $this->actingAs($this->admin)
            ->post(route('manage.assistant.send'), ['message' => 'مرحبا'])
            ->assertOk()
            ->streamedContent();

        $usage = UsageEvent::query()->sole();

        expect($usage->feature)->toBe('admin_assistant')
            ->and((float) ($usage->cost_usd ?? 0.0))->toBe(0.0);
    });

    it('starts a fresh thread when the conversation id belongs to another admin', function () {
        AdminAssistant::fake(['رد.']);

        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('admin');
        $foreignConversationId = createAdminConversation($otherAdmin);

        $this->actingAs($this->admin)
            ->post(route('manage.assistant.send'), ['message' => 'سؤال', 'conversation_id' => $foreignConversationId])
            ->assertOk()
            ->streamedContent();

        expect(Conversation::query()->count())->toBe(2)
            ->and(ConversationMessage::query()->where('conversation_id', $foreignConversationId)->count())->toBe(2);
    });

    it('returns the stored thread with an empty pending set when nothing is paused', function () {
        $conversationId = createAdminConversation($this->admin);

        $this->actingAs($this->admin)
            ->getJson(route('manage.assistant.show', $conversationId))
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.content', 'أخفِ الصفحة.')
            ->assertJsonPath('pending_approvals', []);
    });

    it('answers 404 when another admin requests the thread', function () {
        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('admin');
        $conversationId = createAdminConversation($otherAdmin);

        $this->actingAs($this->admin)
            ->getJson(route('manage.assistant.show', $conversationId))
            ->assertNotFound();
    });
});

/**
 * ai-kit v0.5.0 emits `reasoning` and `tool` by default. Neither is
 * persisted — they exist so the panel can show what the assistant is doing
 * while it does it — so these assert the WIRE, not the stored thread.
 */
describe('reasoning and tool streaming', function () {
    it('brackets a read tool call with running and done tool events', function () {
        AdminAssistant::fake([
            new ToolCall('tc_1', 'get_settings', []),
            'هذه الإعدادات الحالية.',
        ]);

        [, $content] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'get_settings', []), 'ما الإعدادات؟');

        $events = adminSseEvents($content, 'tool');

        expect($events)->toHaveCount(2)
            ->and($events[0]['id'])->toBe('tc_1')
            ->and($events[0]['name'])->toBe('get_settings')
            ->and($events[0]['status'])->toBe('running')
            ->and($events[1]['id'])->toBe('tc_1')
            ->and($events[1]['status'])->toBe('done')
            ->and($events[1]['successful'])->toBeTrue();
    });

    it('streams thinking as reasoning events, in both provider spellings', function () {
        fakeOpenRouter([
            OpenRouterSse::body([
                OpenRouterSse::reasoningFrame('أراجع شجرة الصفحات '),
                OpenRouterSse::reasoningFrame('قبل أي اقتراح.', field: 'reasoning_content'),
                OpenRouterSse::chunk(['content' => 'تم.'], finishReason: 'stop'),
                OpenRouterSse::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.001]),
            ]),
        ]);

        $content = $this->actingAs($this->admin)
            ->post(route('manage.assistant.send'), ['message' => 'رتب الصفحات'])
            ->assertOk()
            ->streamedContent();

        $reasoning = implode('', array_column(adminSseEvents($content, 'reasoning'), 'text'));

        expect($reasoning)->toBe('أراجع شجرة الصفحات قبل أي اقتراح.')
            ->and($content)->toContain('event: delta');
    });

    it('emits a running tool event carrying the paused call id, and never its done', function () {
        $page = Page::factory()->create(['title' => 'اللوائح']);

        AdminAssistant::fake([
            new ToolCall('tc_1', 'manage_page_structure', ['action' => 'rename', 'page_id' => $page->id, 'title' => 'اللوائح الدراسية']),
        ]);

        [, $content] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'manage_page_structure', []));

        $events = adminSseEvents($content, 'tool');
        $card = adminSseEventData($content, 'approval');

        // The client folds this chip into the card that shares its id; a
        // `done` would only arrive on the resumed turn.
        expect($events)->toHaveCount(1)
            ->and($events[0]['status'])->toBe('running')
            ->and($events[0]['id'])->toBe($card['id']);
    });
});

describe('approval pause via tools', function () {
    it('pauses a payload write with an editable approval card and applies nothing', function () {
        $page = Page::factory()->create(['title' => 'اللوائح']);

        AdminAssistant::fake([
            new ToolCall('tc_1', 'manage_page_structure', ['action' => 'rename', 'page_id' => $page->id, 'title' => 'اللوائح الدراسية']),
        ]);

        [$conversationId, $content] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'manage_page_structure', []));

        $card = adminSseEventData($content, 'approval');

        expect($card)->not->toBeNull()
            ->and($card['id'])->toBe('tc_1')
            ->and($card['tool'])->toBe('manage_page_structure')
            ->and($card['category'])->toBe('pages')
            ->and($card['destructive'])->toBeFalse()
            ->and($card['editable'])->toBeTrue()
            ->and($card['arguments']['title'])->toBe('اللوائح الدراسية')
            ->and($card['reason'])->toContain('اللوائح الدراسية');

        // Two-phase contract: nothing is applied while the card waits.
        expect($page->refresh()->title)->toBe('اللوائح')
            ->and((new StoredApprovals)->pending($conversationId)->pluck('id')->all())->toBe(['tc_1']);
    });

    it('pauses a destructive delete as a one-click card', function () {
        $tutor = PrivateTutorFactory::new()->create();

        AdminAssistant::fake([
            new ToolCall('tc_1', 'delete_tutor', ['tutor_id' => $tutor->id]),
        ]);

        [, $content] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'delete_tutor', []));

        $card = adminSseEventData($content, 'approval');

        expect($card)->not->toBeNull()
            ->and($card['destructive'])->toBeTrue()
            ->and($card['editable'])->toBeFalse();

        expect(PrivateTutor::query()->whereKey($tutor->id)->exists())->toBeTrue();
    });

    it('pauses an AskUser call as a question card', function () {
        AdminAssistant::fake([
            new ToolCall('tc_1', 'AskUser', ['question' => 'أي قسم تقصد؟']),
        ]);

        [, $content] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'AskUser', []));

        $card = adminSseEventData($content, 'question');

        expect($card)->not->toBeNull()
            ->and($card['id'])->toBe('tc_1')
            ->and($card['question'])->toBe('أي قسم تقصد؟');
    });

    it('skips the pause and answers the model directly when validation fails', function () {
        AdminAssistant::fake([
            new ToolCall('tc_1', 'manage_page_structure', ['action' => 'rename', 'page_id' => 999, 'title' => 'جديد']),
            'تعذر تنفيذ الإجراء.',
        ]);

        [$conversationId, $content] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'manage_page_structure', []));

        expect(adminSseEventData($content, 'approval'))->toBeNull()
            ->and((new StoredApprovals)->pending($conversationId))->toBeEmpty();
    });

    it('runs read tools immediately without a card', function () {
        AdminAssistant::fake([
            new ToolCall('tc_1', 'get_settings', []),
            'هذه الإعدادات الحالية.',
        ]);

        [$conversationId, $content] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'get_settings', []), 'ما الإعدادات؟');

        expect(adminSseEventData($content, 'approval'))->toBeNull()
            ->and($content)->toContain('event: delta')
            ->and((new StoredApprovals)->pending($conversationId))->toBeEmpty();
    });

    it('repaints the pending card on show for a reloaded client', function () {
        $page = Page::factory()->create(['title' => 'اللوائح']);

        AdminAssistant::fake([
            new ToolCall('tc_1', 'manage_page_structure', ['action' => 'rename', 'page_id' => $page->id, 'title' => 'جديد']),
        ]);

        [$conversationId] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'manage_page_structure', []));

        $this->actingAs($this->admin)
            ->getJson(route('manage.assistant.show', $conversationId))
            ->assertOk()
            ->assertJsonPath('pending_approvals.0.id', 'tc_1')
            ->assertJsonPath('pending_approvals.0.tool', 'manage_page_structure')
            ->assertJsonPath('pending_approvals.0.category', 'pages');
    });
});

describe('deciding paused turns', function () {
    it('applies the write on approve, streams the continuation, and clears the pause', function () {
        $page = Page::factory()->create(['title' => 'قديم']);

        fakeOpenRouter([
            OpenRouterSse::toolCallBody('tc_1', 'manage_page_structure', ['action' => 'rename', 'page_id' => $page->id, 'title' => 'جديد']),
            OpenRouterSse::textBody('نفّذت إعادة التسمية.'),
        ]);

        Cache::put(config('app-cache.keys.navigation_tree'), ['stale']);

        [$conversationId] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'manage_page_structure', []));

        $content = $this->actingAs($this->admin)
            ->post(route('manage.assistant.decide', $conversationId), ['decisions' => ['tc_1' => 'approve']])
            ->assertOk()
            ->streamedContent();

        expect($content)->toContain('event: delta')
            ->and($content)->toContain('event: done')
            ->and($page->refresh()->title)->toBe('جديد')
            // Eloquent write path: model events flushed the app caches.
            ->and(Cache::has(config('app-cache.keys.navigation_tree')))->toBeFalse()
            ->and((new StoredApprovals)->pending($conversationId))->toBeEmpty();

        $execution = DB::table('ai_write_executions')->sole();

        expect($execution->turn_id)->toBe('tool:tc_1')
            ->and($execution->executed_by)->toBe('admin:'.$this->admin->id)
            ->and(json_decode($execution->result, true)['edited_by_user'])->toBeFalse();
    });

    it('executes user-edited arguments through the same validated path and flags the audit trail', function () {
        $page = Page::factory()->create(['title' => 'قديم']);

        fakeOpenRouter([
            OpenRouterSse::toolCallBody('tc_1', 'manage_page_structure', ['action' => 'rename', 'page_id' => $page->id, 'title' => 'اقتراح النموذج']),
            OpenRouterSse::textBody('نفّذت.'),
        ]);

        [$conversationId] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'manage_page_structure', []));

        $this->actingAs($this->admin)
            ->post(route('manage.assistant.decide', $conversationId), ['decisions' => ['tc_1' => [
                'action' => 'edit',
                'arguments' => ['action' => 'rename', 'page_id' => $page->id, 'title' => 'تعديل المشرف'],
            ]]])
            ->assertOk()
            ->streamedContent();

        expect($page->refresh()->title)->toBe('تعديل المشرف')
            ->and(json_decode(DB::table('ai_write_executions')->sole()->result, true)['edited_by_user'])->toBeTrue();
    });

    it('applies nothing on reject and the model reads the denial', function () {
        $page = Page::factory()->create(['title' => 'قديم']);

        fakeOpenRouter([
            OpenRouterSse::toolCallBody('tc_1', 'manage_page_structure', ['action' => 'rename', 'page_id' => $page->id, 'title' => 'جديد']),
            OpenRouterSse::textBody('حسناً، لن أنفّذ التغيير.'),
        ]);

        [$conversationId] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'manage_page_structure', []));

        $content = $this->actingAs($this->admin)
            ->post(route('manage.assistant.decide', $conversationId), ['decisions' => ['tc_1' => ['action' => 'reject']]])
            ->assertOk()
            ->streamedContent();

        expect($content)->toContain('event: done')
            ->and($page->refresh()->title)->toBe('قديم')
            ->and(DB::table('ai_write_executions')->count())->toBe(0)
            ->and((new StoredApprovals)->pending($conversationId))->toBeEmpty();
    });

    it('resumes an AskUser pause with the answer as an edit decision', function () {
        fakeOpenRouter([
            OpenRouterSse::toolCallBody('tc_1', 'AskUser', ['question' => 'أي قسم تقصد؟']),
            OpenRouterSse::textBody('شكراً — سأعمل على قسم اللوائح.'),
        ]);

        [$conversationId] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'AskUser', []));

        $content = $this->actingAs($this->admin)
            ->post(route('manage.assistant.decide', $conversationId), ['decisions' => ['tc_1' => [
                'action' => 'edit',
                'arguments' => ['question' => 'أي قسم تقصد؟', 'answer' => 'قسم اللوائح'],
            ]]])
            ->assertOk()
            ->streamedContent();

        expect($content)->toContain('event: done')
            ->and((new StoredApprovals)->pending($conversationId))->toBeEmpty();
    });

    it('answers 409 with the current pending set when the decisions do not match the pause', function () {
        $conversationId = createAdminConversation($this->admin);

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.decide', $conversationId), ['decisions' => ['tc_9' => 'approve']])
            ->assertConflict()
            ->assertJsonPath('pending_approvals', []);
    });

    it('answers 422 on a malformed decision payload', function () {
        $page = Page::factory()->create();

        fakeOpenRouter([
            OpenRouterSse::toolCallBody('tc_1', 'manage_page_structure', ['action' => 'rename', 'page_id' => $page->id, 'title' => 'جديد']),
        ]);

        [$conversationId] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'manage_page_structure', []));

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.decide', $conversationId), ['decisions' => ['tc_1' => 'maybe']])
            ->assertUnprocessable();
    });

    it('answers 404 when deciding another admin conversation', function () {
        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('admin');
        $conversationId = createAdminConversation($otherAdmin);

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.decide', $conversationId), ['decisions' => ['tc_1' => 'approve']])
            ->assertNotFound();
    });

    it('replays the first result instead of writing twice on a double-submitted approval', function () {
        $page = Page::factory()->create(['title' => 'قديم']);

        fakeOpenRouter([
            OpenRouterSse::toolCallBody('tc_1', 'manage_page_structure', ['action' => 'rename', 'page_id' => $page->id, 'title' => 'جديد']),
            OpenRouterSse::textBody('نفّذت.'),
        ]);

        [$conversationId] = pauseTurnOn($this->admin, new ToolCall('tc_1', 'manage_page_structure', []));

        $this->actingAs($this->admin)
            ->post(route('manage.assistant.decide', $conversationId), ['decisions' => ['tc_1' => 'approve']])
            ->assertOk()
            ->streamedContent();

        // The pause is resolved, so a second submit repaints via 409 — and
        // even if it raced past that check, the execution ledger holds one
        // claim for tool:tc_1.
        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.decide', $conversationId), ['decisions' => ['tc_1' => 'approve']])
            ->assertConflict();

        expect(DB::table('ai_write_executions')->where('turn_id', 'tool:tc_1')->count())->toBe(1)
            ->and($page->refresh()->title)->toBe('جديد');
    });
});

describe('settings introspection', function () {
    it('lists every settings group with current values through get_settings', function () {
        $output = app(GetSettingsAction::class)->handle([], $this->admin)->message;

        expect($output)->toContain('group: ai')
            ->and($output)->toContain('group: telegram')
            ->and($output)->toContain('chat_model')
            ->and($output)->toContain('page_management_allowed_chat_ids');
    });

    it('refuses while the feature toggle is off', function () {
        $this->actingAs($this->admin);

        disableAdminAssistant();

        $output = (string) (new AssistantActionTool(app(GetSettingsAction::class)))->handle(new ToolRequest([]));

        expect($output)->toContain('معطل');
    });

    it('masks secret-like keys down to their last 4 characters', function () {
        $registry = app(SettingsRegistry::class);

        expect($registry->isSecretKey('bot_token'))->toBeTrue()
            ->and($registry->isSecretKey('api_key'))->toBeTrue()
            ->and($registry->isSecretKey('webhook_secret'))->toBeTrue()
            ->and($registry->isSecretKey('chat_model'))->toBeFalse()
            ->and($registry->mask('1234567890:AAxxYYzz'))->toBe('••••YYzz')
            ->and($registry->mask('abc'))->toBe('••••');
    });
});
