<?php

namespace App\Ai\Spend;

use App\Ai\Gateway\ReasoningOpenRouterGateway;
use App\Models\Ai\AiUsage;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Responses\Data\Usage;

/**
 * The application's AI spend ledger — every paid turn is recorded here, and
 * the summed spend of the current day is the global budget kill switch: once
 * it reaches AiSettings->daily_budget_usd, budget-gated features answer with
 * a polite Arabic "unavailable today" instead of calling the provider.
 *
 * Costs are the EXACT provider-reported USD amounts the custom gateway pushes
 * onto {@see Context} per model round (streamed and non-streamed keys are
 * separate so a helper call never folds into a chat turn). The pattern per
 * turn is: clearContextCosts() before the call, captureContextCosts() after,
 * record() the total.
 *
 * A daily_budget_usd of zero (or negative) means "spend nothing": the budget
 * is treated as already exhausted, making the setting itself an operator
 * kill switch for all paid features.
 */
class SpendLedger
{
    public function __construct(private readonly AiSettings $settings) {}

    /**
     * Record one turn's spend. Always recorded — even a zero-cost turn — so
     * the ledger doubles as a usage log per feature.
     */
    public function record(string $feature, string $model, ?Usage $usage, float $costUsd): AiUsage
    {
        return AiUsage::query()->create([
            'feature' => $feature,
            'model' => $model,
            'prompt_tokens' => $usage?->promptTokens,
            'completion_tokens' => $usage?->completionTokens,
            'cost' => max(0.0, $costUsd),
        ]);
    }

    /**
     * Drop any per-round costs a previous turn left on Context, so the next
     * capture sums only the upcoming call's rounds.
     */
    public function clearContextCosts(): void
    {
        Context::forget(ReasoningOpenRouterGateway::COSTS_CONTEXT_KEY);
        Context::forget(ReasoningOpenRouterGateway::NON_STREAM_COSTS_CONTEXT_KEY);
        Context::forget(ReasoningOpenRouterGateway::GENERATION_IDS_CONTEXT_KEY);
    }

    /**
     * Sum (and clear) the per-round provider costs the gateway captured since
     * the last clear — the exact USD spend of the turn that just finished.
     */
    public function captureContextCosts(): float
    {
        $costs = array_merge(
            (array) Context::get(ReasoningOpenRouterGateway::COSTS_CONTEXT_KEY, []),
            (array) Context::get(ReasoningOpenRouterGateway::NON_STREAM_COSTS_CONTEXT_KEY, []),
        );

        $this->clearContextCosts();

        return array_sum(array_map(floatval(...), $costs));
    }

    /**
     * Today's total recorded spend in USD, across every feature.
     */
    public function todaysSpendUsd(): float
    {
        return (float) AiUsage::query()
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->sum('cost');
    }

    /**
     * Whether budget-gated features may still call the provider today.
     */
    public function hasBudgetRemaining(): bool
    {
        $budget = $this->settings->daily_budget_usd;

        if ($budget <= 0) {
            return false;
        }

        return $this->todaysSpendUsd() < $budget;
    }

    /**
     * The polite Arabic refusal shown when today's budget is exhausted.
     */
    public function budgetExhaustedMessage(): string
    {
        return 'المساعد الذكي غير متاح اليوم — بلغنا الحد اليومي للاستخدام. جرّب مرة أخرى غداً.';
    }
}
