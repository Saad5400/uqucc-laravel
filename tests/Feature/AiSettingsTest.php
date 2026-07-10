<?php

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

    it('has the expected default models', function () {
        $settings = app(AiSettings::class);

        expect($settings->chat_model)->toBe('google/gemini-3.5-flash')
            ->and($settings->vision_model)->toBe('google/gemini-2.5-flash')
            ->and($settings->embedding_model)->toBe('openai/text-embedding-3-small');
    });

    it('has the expected default cost controls', function () {
        $settings = app(AiSettings::class);

        expect($settings->daily_budget_usd)->toBe(5.0)
            ->and($settings->per_session_rate_limit)->toBe(20)
            ->and($settings->per_conversation_rate_limit)->toBe(30);
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
            'chat_model' => 'google/gemini-3.5-flash',
            'vision_model' => 'google/gemini-2.5-flash',
            'embedding_model' => 'openai/text-embedding-3-small',
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
                ->where('ai.chat_model', 'google/gemini-3.5-flash')
                ->where('ai.vision_model', 'google/gemini-2.5-flash')
                ->where('ai.embedding_model', 'openai/text-embedding-3-small')
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
        'missing chat model' => [['chat_model' => ''], 'chat_model', 'حقل نموذج المحادثة مطلوب.'],
        'missing vision model' => [['vision_model' => ''], 'vision_model', 'حقل نموذج الرؤية مطلوب.'],
        'missing embedding model' => [['embedding_model' => ''], 'embedding_model', 'حقل نموذج التضمين مطلوب.'],
        'negative budget' => [['daily_budget_usd' => -1], 'daily_budget_usd', 'الميزانية اليومية لا يمكن أن تكون سالبة.'],
        'non-numeric budget' => [['daily_budget_usd' => 'abc'], 'daily_budget_usd', 'الميزانية اليومية يجب أن تكون رقماً.'],
        'zero session limit' => [['per_session_rate_limit' => 0], 'per_session_rate_limit', 'حد الرسائل لكل جلسة يجب أن يكون 1 على الأقل.'],
        'zero conversation limit' => [['per_conversation_rate_limit' => 0], 'per_conversation_rate_limit', 'حد الرسائل لكل محادثة يجب أن يكون 1 على الأقل.'],
    ]);

    it('does not change settings when validation fails', function () {
        $this->actingAs($this->admin)
            ->put('/manage/settings/ai', validAiSettingsPayload(['chat_model' => '']));

        expect(app(AiSettings::class)->ai_enabled)->toBeFalse();
    });
});
