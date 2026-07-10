<?php

use App\Ai\Gateway\ReasoningOpenRouterGateway;
use App\Ai\Spend\SpendLedger;
use App\Models\Ai\AiUsage;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Responses\Data\Usage;

beforeEach(function () {
    $settings = app(AiSettings::class);
    $settings->daily_budget_usd = 5.0;
    $settings->save();
});

it('records a usage row with tokens and cost', function () {
    $usage = app(SpendLedger::class)->record('assistant', 'google/gemini-3.5-flash', new Usage(1200, 340), 0.0123);

    expect($usage->feature)->toBe('assistant')
        ->and($usage->prompt_tokens)->toBe(1200)
        ->and($usage->completion_tokens)->toBe(340)
        ->and($usage->cost)->toBe(0.0123)
        ->and(AiUsage::query()->count())->toBe(1);
});

it('never records a negative cost', function () {
    $usage = app(SpendLedger::class)->record('assistant', 'model', null, -1.0);

    expect($usage->cost)->toBe(0.0);
});

it('sums only today for the daily spend', function () {
    AiUsage::factory()->create(['cost' => 2.0]);
    AiUsage::factory()->create(['cost' => 1.5]);
    AiUsage::factory()->create(['cost' => 9.0, 'created_at' => now()->subDay()]);

    expect(app(SpendLedger::class)->todaysSpendUsd())->toEqualWithDelta(3.5, 0.000001);
});

it('reports remaining budget until today\'s spend reaches the cap', function () {
    $ledger = app(SpendLedger::class);

    expect($ledger->hasBudgetRemaining())->toBeTrue();

    AiUsage::factory()->create(['cost' => 4.99]);

    expect($ledger->hasBudgetRemaining())->toBeTrue();

    AiUsage::factory()->create(['cost' => 0.02]);

    expect($ledger->hasBudgetRemaining())->toBeFalse();
});

it('treats a zero budget as spend-nothing', function () {
    $settings = app(AiSettings::class);
    $settings->daily_budget_usd = 0.0;
    $settings->save();

    expect(app(SpendLedger::class)->hasBudgetRemaining())->toBeFalse();
});

it('captures and clears the per-round provider costs from context', function () {
    Context::push(ReasoningOpenRouterGateway::COSTS_CONTEXT_KEY, 0.002);
    Context::push(ReasoningOpenRouterGateway::COSTS_CONTEXT_KEY, 0.003);
    Context::push(ReasoningOpenRouterGateway::NON_STREAM_COSTS_CONTEXT_KEY, 0.001);

    $ledger = app(SpendLedger::class);

    expect($ledger->captureContextCosts())->toEqualWithDelta(0.006, 0.0000001)
        ->and($ledger->captureContextCosts())->toBe(0.0);
});
