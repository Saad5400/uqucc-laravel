<?php

use App\Ai\Agents\StudentAssistant;
use App\Ai\Spend\SpendLedger;
use App\Models\Ai\AiUsage;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Saad\AiKit\Usage\UsageEvent;

/**
 * The M2 dual-write contract: ai-kit's usage module records every turn into
 * ai_usage_events from laravel/ai's events, while SpendLedger keeps writing
 * the legacy ai_usage rows by draining the collector itself. Both must see
 * the same provider cost until cutover.
 */
function streamedTurnEvent(): AgentStreamed
{
    $invocationId = (string) Str::uuid7();

    $prompt = new AgentPrompt(
        StudentAssistant::make(),
        'ايش المادة العشرون؟',
        [],
        app(AiManager::class)->textProvider('openrouter'),
        'test/model',
        invocationId: $invocationId,
    );

    $response = new StreamedAgentResponse($invocationId, collect(), new Meta('openrouter', 'test/model'));
    $response->usage = new Usage(promptTokens: 120, completionTokens: 30);

    return new AgentStreamed($invocationId, $prompt, $response);
}

it('records the turn into ai_usage_events under the ledger feature label', function () {
    $ledger = app(SpendLedger::class);
    $ledger->labelTurn('assistant');

    app(\Saad\AiKit\Gateway\SpendCollector::class)->recordCost(0.004, true);

    event(streamedTurnEvent());

    $row = UsageEvent::sole();

    expect($row->feature)->toBe('assistant')
        ->and($row->streamed)->toBeTrue()
        ->and($row->model)->toBe('test/model')
        ->and($row->cost_usd)->toEqualWithDelta(0.004, 0.0000001)
        ->and($row->agent)->toBe(StudentAssistant::class);
});

it('leaves the collector for SpendLedger to drain while dual-writing', function () {
    expect(config('ai-kit.usage.drain_spend'))->toBeFalse();

    $ledger = app(SpendLedger::class);

    app(\Saad\AiKit\Gateway\SpendCollector::class)->recordCost(0.004, true);

    event(streamedTurnEvent());

    // ai-kit recorded the cost without consuming it...
    expect(UsageEvent::sole()->cost_usd)->toEqualWithDelta(0.004, 0.0000001);

    // ...so the legacy ledger still captures the same amount.
    $ledger->record('assistant', 'test/model', new Usage(120, 30), $ledger->captureContextCosts());

    expect(AiUsage::sole()->cost)->toEqualWithDelta(0.004, 0.0000001);
});
