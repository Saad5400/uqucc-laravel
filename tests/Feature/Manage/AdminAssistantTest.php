<?php

use App\Ai\Admin\AdminAssistant;
use App\Ai\Admin\SettingsRegistry;
use App\Ai\Admin\Tools\GetSettingsTool;
use App\Models\Ai\AdminPendingAction;
use App\Models\Ai\AiUsage;
use App\Models\Page;
use App\Models\User;
use App\Settings\AiSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request as ToolRequest;

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

function disableAdminAssistant(bool $masterToo = false): void
{
    $settings = app(AiSettings::class);
    $settings->admin_assistant_enabled = false;

    if ($masterToo) {
        $settings->ai_enabled = false;
    }

    $settings->save();
}

function createAdminConversation(User $admin, array $toolResults = []): string
{
    $conversationId = (string) Str::uuid7();

    Conversation::query()->create([
        'id' => $conversationId,
        'user_id' => 'admin:'.$admin->getKey(),
        'title' => 'محادثة إدارية',
    ]);

    foreach ([['user', 'أخفِ الصفحة.', []], ['assistant', 'أنشأت اقتراحاً بانتظار تأكيدك.', $toolResults]] as [$role, $content, $results]) {
        ConversationMessage::query()->create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => 'admin:'.$admin->getKey(),
            'agent' => AdminAssistant::class,
            'role' => $role,
            'content' => $content,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => $results,
            'usage' => [],
            'meta' => [],
        ]);
    }

    return $conversationId;
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

    it('refuses politely without calling the model once the daily budget is spent', function () {
        AdminAssistant::fake(['يجب ألا يظهر هذا الرد.']);

        AiUsage::factory()->create(['cost' => 6.0]);

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.send'), ['message' => 'مرحبا'])
            ->assertServiceUnavailable();

        AdminAssistant::assertNeverPrompted();
    });

    it('answers 503 on confirm and reject while the feature is off', function () {
        $proposal = AdminPendingAction::factory()->create(['proposed_by' => $this->admin->id]);

        disableAdminAssistant();

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.proposals.confirm', $proposal))
            ->assertServiceUnavailable();

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.proposals.reject', $proposal))
            ->assertServiceUnavailable();

        expect($proposal->refresh()->status)->toBe(AdminPendingAction::STATUS_PENDING);
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

        expect($conversation->getAttribute('user_id'))->toBe('admin:'.$this->admin->id)
            ->and(adminSseEventData($content, 'done')['conversation_id'])->toBe($conversation->getKey());
    });

    it('records the turn on the spend ledger under the admin_assistant feature', function () {
        AdminAssistant::fake(['رد قصير.']);

        $this->actingAs($this->admin)
            ->post(route('manage.assistant.send'), ['message' => 'مرحبا'])
            ->assertOk()
            ->streamedContent();

        $usage = AiUsage::query()->sole();

        expect($usage->feature)->toBe('admin_assistant')
            ->and($usage->cost)->toBe(0.0);
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

    it('returns the stored thread with proposals carrying their CURRENT status', function () {
        $proposal = AdminPendingAction::factory()->confirmed()->create(['proposed_by' => $this->admin->id]);

        $conversationId = createAdminConversation($this->admin, [[
            'id' => 'tc_1',
            'name' => 'propose_page_change',
            'arguments' => [],
            'result' => "تم إنشاء اقتراح بانتظار تأكيد المشرف.\n---\nproposal_id: {$proposal->id}",
        ]]);

        $this->actingAs($this->admin)
            ->getJson(route('manage.assistant.show', $conversationId))
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.1.proposals.0.id', $proposal->id)
            ->assertJsonPath('messages.1.proposals.0.status', AdminPendingAction::STATUS_CONFIRMED);
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

describe('proposal creation via tools', function () {
    it('persists a pending page proposal from a tool call and emits it as an SSE proposal event', function () {
        $page = Page::factory()->create(['title' => 'اللوائح']);

        AdminAssistant::fake([
            new ToolCall('tc_1', 'propose_page_change', ['action' => 'rename', 'page_id' => $page->id, 'title' => 'اللوائح الدراسية']),
            'أنشأت اقتراحاً بإعادة التسمية — بانتظار تأكيدك.',
        ]);

        $content = $this->actingAs($this->admin)
            ->post(route('manage.assistant.send'), ['message' => 'أعد تسمية صفحة اللوائح'])
            ->assertOk()
            ->streamedContent();

        $proposal = AdminPendingAction::query()->sole();

        expect($proposal->status)->toBe(AdminPendingAction::STATUS_PENDING)
            ->and($proposal->type)->toBe(AdminPendingAction::TYPE_PAGE_CHANGE)
            ->and($proposal->proposed_by)->toBe($this->admin->id)
            ->and($proposal->payload['action'])->toBe('rename')
            ->and($proposal->payload['title'])->toBe('اللوائح الدراسية');

        $event = adminSseEventData($content, 'proposal');

        expect($event)->not->toBeNull()
            ->and($event['id'])->toBe($proposal->id)
            ->and($event['status'])->toBe(AdminPendingAction::STATUS_PENDING)
            ->and($event['summary'])->toContain('اللوائح الدراسية');

        // Two-phase contract: nothing is applied at proposal time.
        expect($page->refresh()->title)->toBe('اللوائح');
    });

    it('persists a pending settings proposal from a tool call', function () {
        AdminAssistant::fake([
            new ToolCall('tc_1', 'propose_settings_change', ['group' => 'ai', 'key' => 'search_enabled', 'value' => 'true']),
            'أنشأت الاقتراح — بانتظار تأكيدك.',
        ]);

        $content = $this->actingAs($this->admin)
            ->post(route('manage.assistant.send'), ['message' => 'فعّل البحث الذكي'])
            ->assertOk()
            ->streamedContent();

        $proposal = AdminPendingAction::query()->sole();

        expect($proposal->type)->toBe(AdminPendingAction::TYPE_SETTINGS_CHANGE)
            ->and($proposal->payload['group'])->toBe('ai')
            ->and($proposal->payload['key'])->toBe('search_enabled')
            ->and($proposal->payload['value'])->toBeTrue()
            ->and(adminSseEventData($content, 'proposal'))->not->toBeNull();

        expect(app(AiSettings::class)->search_enabled)->toBeFalse();
    });

    it('creates no proposal when the page id does not exist', function () {
        AdminAssistant::fake([
            new ToolCall('tc_1', 'propose_page_change', ['action' => 'rename', 'page_id' => 999, 'title' => 'جديد']),
            'تعذر إنشاء الاقتراح.',
        ]);

        $content = $this->actingAs($this->admin)
            ->post(route('manage.assistant.send'), ['message' => 'أعد التسمية'])
            ->assertOk()
            ->streamedContent();

        expect(AdminPendingAction::query()->count())->toBe(0)
            ->and(adminSseEventData($content, 'proposal'))->toBeNull();
    });

    it('creates no proposal for an unknown settings key or a type-mismatched value', function (array $arguments) {
        AdminAssistant::fake([
            new ToolCall('tc_1', 'propose_settings_change', $arguments),
            'تعذر إنشاء الاقتراح.',
        ]);

        $this->actingAs($this->admin)
            ->post(route('manage.assistant.send'), ['message' => 'غيّر الإعداد'])
            ->assertOk()
            ->streamedContent();

        expect(AdminPendingAction::query()->count())->toBe(0);
    })->with([
        'unknown group' => [['group' => 'mail', 'key' => 'driver', 'value' => 'smtp']],
        'unknown key' => [['group' => 'ai', 'key' => 'nonexistent_key', 'value' => 'true']],
        'boolean type mismatch' => [['group' => 'ai', 'key' => 'search_enabled', 'value' => 'ربما']],
        'integer type mismatch' => [['group' => 'ai', 'key' => 'per_session_rate_limit', 'value' => 'كثير']],
    ]);
});

describe('confirming proposals', function () {
    it('applies a rename through Eloquent (model events flush the app caches) and marks it confirmed', function () {
        $page = Page::factory()->create(['title' => 'قديم']);

        $proposal = AdminPendingAction::factory()->create([
            'proposed_by' => $this->admin->id,
            'payload' => ['action' => 'rename', 'page_id' => $page->id, 'title' => 'جديد'],
        ]);

        Cache::put(config('app-cache.keys.navigation_tree'), ['stale']);

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.proposals.confirm', $proposal))
            ->assertOk()
            ->assertJsonPath('proposal.status', AdminPendingAction::STATUS_CONFIRMED);

        expect($page->refresh()->title)->toBe('جديد')
            ->and($proposal->refresh()->executed_at)->not->toBeNull()
            ->and(Cache::has(config('app-cache.keys.navigation_tree')))->toBeFalse();
    });

    it('applies a move to a new parent, placing the page at the end of its new siblings', function () {
        $oldParent = Page::factory()->create();
        $newParent = Page::factory()->create();
        $sibling = Page::factory()->create(['parent_id' => $newParent->id, 'order' => 3]);
        $page = Page::factory()->create(['parent_id' => $oldParent->id]);

        $proposal = AdminPendingAction::factory()->create([
            'proposed_by' => $this->admin->id,
            'payload' => ['action' => 'move', 'page_id' => $page->id, 'parent_id' => $newParent->id],
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.proposals.confirm', $proposal))
            ->assertOk()
            ->assertJsonPath('proposal.status', AdminPendingAction::STATUS_CONFIRMED);

        expect($page->refresh()->parent_id)->toBe($newParent->id)
            ->and($page->order)->toBeGreaterThan($sibling->order);
    });

    it('applies publish and unpublish through the hidden flag', function () {
        $page = Page::factory()->create(['hidden' => true]);

        $publish = AdminPendingAction::factory()->create([
            'proposed_by' => $this->admin->id,
            'payload' => ['action' => 'publish', 'page_id' => $page->id],
        ]);

        $this->actingAs($this->admin)->postJson(route('manage.assistant.proposals.confirm', $publish))->assertOk();

        expect($page->refresh()->hidden)->toBeFalse();

        $unpublish = AdminPendingAction::factory()->create([
            'proposed_by' => $this->admin->id,
            'payload' => ['action' => 'unpublish', 'page_id' => $page->id],
        ]);

        $this->actingAs($this->admin)->postJson(route('manage.assistant.proposals.confirm', $unpublish))->assertOk();

        expect($page->refresh()->hidden)->toBeTrue();
    });

    it('applies a sibling reorder with sequential orders', function () {
        $parent = Page::factory()->create();
        $first = Page::factory()->create(['parent_id' => $parent->id, 'order' => 1]);
        $second = Page::factory()->create(['parent_id' => $parent->id, 'order' => 2]);

        $proposal = AdminPendingAction::factory()->create([
            'proposed_by' => $this->admin->id,
            'payload' => ['action' => 'reorder', 'ids' => [$second->id, $first->id]],
        ]);

        $this->actingAs($this->admin)->postJson(route('manage.assistant.proposals.confirm', $proposal))->assertOk();

        expect($second->refresh()->order)->toBe(1)
            ->and($first->refresh()->order)->toBe(2);
    });

    it('soft deletes a page together with its descendants', function () {
        $page = Page::factory()->create();
        $child = Page::factory()->create(['parent_id' => $page->id]);

        $proposal = AdminPendingAction::factory()->create([
            'proposed_by' => $this->admin->id,
            'payload' => ['action' => 'delete', 'page_id' => $page->id],
        ]);

        $this->actingAs($this->admin)->postJson(route('manage.assistant.proposals.confirm', $proposal))->assertOk();

        expect($page->refresh()->trashed())->toBeTrue()
            ->and($child->refresh()->trashed())->toBeTrue();
    });

    it('applies a settings change round-trip', function () {
        expect(app(AiSettings::class)->search_enabled)->toBeFalse();

        $proposal = AdminPendingAction::factory()
            ->settingsChange('ai', 'search_enabled', 'true')
            ->create(['proposed_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.proposals.confirm', $proposal))
            ->assertOk()
            ->assertJsonPath('proposal.status', AdminPendingAction::STATUS_CONFIRMED);

        expect(app(AiSettings::class)->refresh()->search_enabled)->toBeTrue();
    });

    it('marks the proposal failed with the reason when re-validation fails (page vanished)', function () {
        $page = Page::factory()->create();

        $proposal = AdminPendingAction::factory()->create([
            'proposed_by' => $this->admin->id,
            'payload' => ['action' => 'rename', 'page_id' => $page->id, 'title' => 'جديد'],
        ]);

        $page->forceDelete();

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.proposals.confirm', $proposal))
            ->assertOk()
            ->assertJsonPath('proposal.status', AdminPendingAction::STATUS_FAILED);

        expect($proposal->refresh()->error)->not->toBeNull();
    });

    it('answers 409 when the proposal is no longer pending', function () {
        $proposal = AdminPendingAction::factory()->rejected()->create(['proposed_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.proposals.confirm', $proposal))
            ->assertConflict()
            ->assertJsonPath('proposal.status', AdminPendingAction::STATUS_REJECTED);
    });
});

describe('rejecting proposals', function () {
    it('marks the proposal rejected and applies nothing', function () {
        $page = Page::factory()->create(['title' => 'قديم']);

        $proposal = AdminPendingAction::factory()->create([
            'proposed_by' => $this->admin->id,
            'payload' => ['action' => 'rename', 'page_id' => $page->id, 'title' => 'جديد'],
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.proposals.reject', $proposal))
            ->assertOk()
            ->assertJsonPath('proposal.status', AdminPendingAction::STATUS_REJECTED);

        expect($page->refresh()->title)->toBe('قديم')
            ->and($proposal->refresh()->executed_at)->toBeNull();
    });

    it('answers 409 when rejecting a proposal that was already confirmed', function () {
        $proposal = AdminPendingAction::factory()->confirmed()->create(['proposed_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->postJson(route('manage.assistant.proposals.reject', $proposal))
            ->assertConflict();
    });
});

describe('settings introspection', function () {
    it('lists every settings group with current values through get_settings', function () {
        $this->actingAs($this->admin);

        $output = (string) app(GetSettingsTool::class)->handle(new ToolRequest([]));

        expect($output)->toContain('group: ai')
            ->and($output)->toContain('group: telegram')
            ->and($output)->toContain('chat_model')
            ->and($output)->toContain('page_management_allowed_chat_ids');
    });

    it('refuses while the feature toggle is off', function () {
        disableAdminAssistant();

        $output = (string) app(GetSettingsTool::class)->handle(new ToolRequest([]));

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
