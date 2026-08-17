<?php

use App\Settings\AiSettings;
use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Safety\KillSwitch;
use Saad\AiKit\Safety\SafetySettings;

beforeEach(function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->assistant_enabled = true;
    $settings->telegram_ai_enabled = true;
    $settings->daily_budget_usd = 5.0;
    $settings->save();
});

it('feeds the operator budget into ai-kit', function () {
    expect(app(BudgetGuard::class)->limit())->toBe(5.0);
});

it('treats a zero budget as spend-nothing', function () {
    $settings = app(AiSettings::class);
    $settings->daily_budget_usd = 0.0;
    $settings->save();

    expect(app(BudgetGuard::class)->exceeded())->toBeTrue();
});

it('reports exceeded once recorded spend reaches the cap', function () {
    $budget = app(BudgetGuard::class);

    $budget->record(4.99);
    expect($budget->exceeded())->toBeFalse();

    $budget->record(0.02);
    expect($budget->exceeded())->toBeTrue();
});

it('maps the master toggle onto the kill switch', function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->save();

    expect(app(KillSwitch::class)->engaged())->toBeTrue()
        ->and(app(KillSwitch::class)->engaged('assistant'))->toBeTrue();
});

it('maps feature toggles onto their own scopes only', function () {
    $settings = app(AiSettings::class);
    $settings->assistant_enabled = false;
    $settings->save();

    expect(app(KillSwitch::class)->engaged('assistant'))->toBeTrue()
        ->and(app(KillSwitch::class)->engaged('telegram'))->toBeFalse();
});

it('falls back to the master switch for unknown feature names', function () {
    expect(app(SafetySettings::class)->enabled('some_future_surface'))->toBeTrue();

    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->save();

    expect(app(SafetySettings::class)->enabled('some_future_surface'))->toBeFalse();
});
