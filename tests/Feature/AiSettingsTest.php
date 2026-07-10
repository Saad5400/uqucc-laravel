<?php

use App\Filament\Pages\ManageAiSettings;
use App\Settings\AiSettings;
use Filament\Pages\SettingsPage;

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

describe('ManageAiSettings Filament page', function () {
    it('is a settings page bound to AiSettings', function () {
        expect(new ManageAiSettings)->toBeInstanceOf(SettingsPage::class)
            ->and(ManageAiSettings::getSettings())->toBe(AiSettings::class);
    });
});
