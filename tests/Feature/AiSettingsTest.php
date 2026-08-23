<?php

use App\Ai\ModelRegistry;
use App\Models\User;
use App\Settings\AiSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

describe('AiSettings defaults', function () {
    it('has all AI features disabled by default', function () {
        $settings = app(AiSettings::class);

        expect($settings->ai_enabled)->toBeFalse()
            ->and($settings->search_enabled)->toBeFalse()
            ->and($settings->assistant_enabled)->toBeFalse()
            ->and($settings->telegram_ai_enabled)->toBeFalse()
            ->and($settings->admin_copilot_enabled)->toBeFalse();
    });

    it('has the expected default cost controls', function () {
        $settings = app(AiSettings::class);

        expect($settings->daily_budget_usd)->toBe(5.0)
            ->and($settings->per_session_rate_limit)->toBe(20)
            ->and($settings->per_conversation_rate_limit)->toBe(30);
    });
});

describe('model resolution', function () {
    // ai-kit docs/DECISIONS.md #26: model choice is CONFIG, and the database
    // row that used to sit above it is gone. These assert the whole remaining
    // chain, because the incident that produced #26 was a layer nobody
    // realised was winning.
    it('serves the kit\'s shared fleet defaults when this app pins nothing', function () {
        config()->set('ai.chat.model', null);
        config()->set('ai.chat.reasoning_effort', null);
        config()->set('ai.vision.model', null);

        $models = app(ModelRegistry::class);

        expect($models->chat())->toBe('deepseek/deepseek-v4-flash')
            ->and($models->chatReasoningEffort())->toBe('medium')
            ->and($models->vision())->toBe('google/gemini-2.5-flash-lite');
    });

    it('lets this app override the fleet default through its own config', function () {
        config()->set('ai.chat.model', 'anthropic/claude-test');
        config()->set('ai.vision.model', 'openai/gpt-vision-test');

        $models = app(ModelRegistry::class);

        expect($models->chat())->toBe('anthropic/claude-test')
            ->and($models->vision())->toBe('openai/gpt-vision-test');
    });

    it('treats a blank config value as inherit, never as a nameless model', function () {
        config()->set('ai.chat.model', '   ');

        expect(app(ModelRegistry::class)->chat())->toBe('deepseek/deepseek-v4-flash');
    });

    it('keeps chat and vision on different models, because the chat model cannot see', function () {
        $models = app(ModelRegistry::class);

        expect($models->vision())->not->toBe($models->chat());
    });

    it('no longer exposes a database row that can override config', function () {
        // The three model rows were deleted by the 2026-08-24 settings
        // migration; reflection is what the admin assistant's SettingsRegistry
        // reads, so this also proves the assistant can no longer set them.
        $properties = array_map(
            fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(AiSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC),
        );

        expect($properties)->not->toContain('chat_model')
            ->and($properties)->not->toContain('vision_model')
            ->and($properties)->not->toContain('embedding_model');
    });
});

describe('AiSettings feature checks', function () {
    it('reports every feature as disabled when the master switch is off', function () {
        $settings = app(AiSettings::class);
        $settings->search_enabled = true;
        $settings->telegram_ai_enabled = true;
        $settings->save();

        expect($settings->isFeatureEnabled('search'))->toBeFalse()
            ->and($settings->isFeatureEnabled('telegram'))->toBeFalse();
    });

    it('honors per-feature toggles when the master switch is on', function () {
        $settings = app(AiSettings::class);
        $settings->ai_enabled = true;
        $settings->search_enabled = true;
        $settings->save();

        expect($settings->isFeatureEnabled('search'))->toBeTrue()
            ->and($settings->isFeatureEnabled('assistant'))->toBeFalse()
            ->and($settings->isFeatureEnabled('telegram'))->toBeFalse()
            ->and($settings->isFeatureEnabled('admin_copilot'))->toBeFalse();
    });

    it('returns false for unknown features', function () {
        $settings = app(AiSettings::class);
        $settings->ai_enabled = true;
        $settings->save();

        expect($settings->isFeatureEnabled('unknown_feature'))->toBeFalse();
    });
});

describe('manage settings AI card', function () {
    /** A valid full payload for the explicit-save AI card. */
    function validAiSettingsPayload(array $overrides = []): array
    {
        return array_merge([
            'ai_enabled' => true,
            'search_enabled' => true,
            'assistant_enabled' => false,
            'telegram_ai_enabled' => false,
            'admin_copilot_enabled' => true,
            'admin_assistant_enabled' => false,
            'daily_budget_usd' => 7.5,
            'per_session_rate_limit' => 25,
            'per_conversation_rate_limit' => 40,
        ], $overrides);
    }

    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    });

    it('redirects guests to the login page', function () {
        $this->put('/manage/settings/ai', validAiSettingsPayload())
            ->assertRedirect(route('manage.login'));
    });

    it('returns 403 for users without a panel role', function () {
        $this->actingAs(User::factory()->create());

        $this->put('/manage/settings/ai', validAiSettingsPayload())->assertForbidden();
    });

    it('shares the AI settings with the settings page', function () {
        $this->actingAs($this->admin)
            ->get('/manage/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->component('manage/settings/Index')
                ->where('ai.ai_enabled', false)
                ->where('models.chat', 'deepseek/deepseek-v4-flash')
                ->where('models.vision', 'google/gemini-2.5-flash-lite')
                ->where('ai.daily_budget_usd', 5)
                ->where('ai.per_session_rate_limit', 20)
                ->where('ai.per_conversation_rate_limit', 30)
            );
    });

    it('allows any panel user to save the AI settings (parity with the previous admin page)', function () {
        $editor = User::factory()->create();
        $editor->assignRole('editor');

        $this->actingAs($editor)
            ->from('/manage/settings')
            ->put('/manage/settings/ai', validAiSettingsPayload())
            ->assertRedirect('/manage/settings')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $settings = app(AiSettings::class);

        expect($settings->ai_enabled)->toBeTrue()
            ->and($settings->search_enabled)->toBeTrue()
            ->and($settings->assistant_enabled)->toBeFalse()
            ->and($settings->admin_copilot_enabled)->toBeTrue()
            ->and($settings->daily_budget_usd)->toBe(7.5)
            ->and($settings->per_session_rate_limit)->toBe(25)
            ->and($settings->per_conversation_rate_limit)->toBe(40);
    });

    it('rejects invalid payloads with Arabic messages', function (array $overrides, string $field, string $message) {
        $this->actingAs($this->admin)
            ->put('/manage/settings/ai', validAiSettingsPayload($overrides))
            ->assertSessionHasErrors([$field => $message]);
    })->with([
        'negative budget' => [['daily_budget_usd' => -1], 'daily_budget_usd', 'الميزانية اليومية لا يمكن أن تكون سالبة.'],
        'non-numeric budget' => [['daily_budget_usd' => 'abc'], 'daily_budget_usd', 'الميزانية اليومية يجب أن تكون رقماً.'],
        'zero session limit' => [['per_session_rate_limit' => 0], 'per_session_rate_limit', 'حد الرسائل لكل جلسة يجب أن يكون 1 على الأقل.'],
        'zero conversation limit' => [['per_conversation_rate_limit' => 0], 'per_conversation_rate_limit', 'حد الرسائل لكل محادثة يجب أن يكون 1 على الأقل.'],
    ]);

    it('does not change settings when validation fails', function () {
        $this->actingAs($this->admin)
            ->put('/manage/settings/ai', validAiSettingsPayload(['per_session_rate_limit' => 0]));

        expect(app(AiSettings::class)->ai_enabled)->toBeFalse();
    });
});
