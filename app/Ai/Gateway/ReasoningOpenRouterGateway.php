<?php

namespace App\Ai\Gateway;

use Generator;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

/**
 * OpenRouter gateway doing the two things the stock laravel/ai 0.9 gateway
 * does not:
 *
 *  1. Captures OpenRouter's reported per-round USD cost (`usage.cost`) and
 *     pushes it onto {@see Context}, so callers can log/attribute the exact
 *     provider spend of a turn without re-estimating from token counts.
 *  2. Re-emits the model's streamed `reasoning` deltas — the stock gateway
 *     forwards only `content` and `tool_calls` and silently drops
 *     `reasoning`, so a chat panel's "thinking" stream would never appear.
 *
 * In laravel/ai 0.9 the multi-step tool-call recursion lives in
 * TextGenerationLoop, not the gateway: `processTextStream()` handles a SINGLE
 * step and returns a StepResponse, while the loop accumulates usage across
 * steps and emits the final StreamEnd. This subclass therefore re-implements
 * only the single-step stream parse (to add reasoning + cost) and never
 * touches StreamStart/StreamEnd ordering or tool recursion.
 *
 * Usage TOKENS are summed across rounds by the loop automatically, but the
 * Usage value object carries no cost field, so cost is NOT summed for us —
 * the per-round Context list below is how a caller sums a multi-step turn's
 * true cost. One push per step; streamed and non-streamed costs are kept in
 * separate keys so a helper call (routing, title generation) never folds into
 * a streamed assistant turn.
 *
 * Registered as the "openrouter" driver in {@see \App\Providers\AiServiceProvider}.
 */
class ReasoningOpenRouterGateway extends OpenRouterGateway
{
    /**
     * Context key: exact OpenRouter USD costs from STREAMED assistant rounds,
     * one value per model round. Callers sum these per turn.
     */
    public const COSTS_CONTEXT_KEY = 'ai.openrouter_costs';

    /**
     * Context key: exact OpenRouter USD costs from NON-STREAMED calls, kept
     * separate so helper calls never merge into a streamed turn's costs.
     */
    public const NON_STREAM_COSTS_CONTEXT_KEY = 'ai.openrouter_non_stream_costs';

    /**
     * Context key: OpenRouter generation ids (gen-…) from streamed rounds, so
     * an anomaly is drillable to the exact request via the generation API.
     */
    public const GENERATION_IDS_CONTEXT_KEY = 'ai.openrouter_generation_ids';

    /**
     * Ask OpenRouter to include `usage` (and therefore `usage.cost`) in the
     * response, on top of the stock body. Late-binds into both the streamed
     * and non-streamed step builders.
     */
    protected function buildStepBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        $body = parent::buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $body['usage'] = array_merge($body['usage'] ?? [], ['include' => true]);

        return $body;
    }

    /**
     * Non-streamed parse (used by helper calls). The stock generateTextStep
     * calls this with the raw response, so overriding here captures the
     * reported cost as a side-effect without re-implementing the whole step.
     *
     * @param  array<string, mixed>  $data
     */
    protected function parseTextResponse(array $data, Provider $provider, bool $structured): StepResponse
    {
        $cost = $this->extractOpenRouterCost($data);

        if ($cost !== null) {
            Context::push(self::NON_STREAM_COSTS_CONTEXT_KEY, $cost);
        }

        return parent::parseTextResponse($data, $provider, $structured);
    }

    /**
     * Single streamed step. Mirrors the stock 0.9 processTextStream
     * (StreamStart, text deltas, tool-call assembly, StepResponse return) and
     * adds two things: reasoning deltas from `delta.reasoning`, and per-round
     * cost capture.
     *
     * @return Generator<int, StreamEvent, mixed, StepResponse|null>
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        $streamBody,
    ): Generator {
        $messageId = $this->generateEventId();
        $streamModel = $model;
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $toolCalls = [];
        $pendingToolCalls = [];
        $usage = null;
        $openRouterCost = null;
        $generationId = '';
        $finishReason = null;

        // Reasoning state (the stock gateway drops delta.reasoning entirely).
        $reasoningId = '';
        $inReasoning = false;

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            if (isset($data['id']) && is_string($data['id']) && $data['id'] !== '') {
                $generationId = $data['id'];
            }

            if (isset($data['error'])) {
                yield (new Error(
                    $this->generateEventId(),
                    $data['error']['code'] ?? 'unknown_error',
                    $data['error']['message'] ?? 'Unknown error',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            $choice = $data['choices'][0] ?? null;

            if (! $choice) {
                if (isset($data['usage'])) {
                    $usage = $this->extractUsage($data);
                    $openRouterCost = $this->extractOpenRouterCost($data) ?? $openRouterCost;
                }

                continue;
            }

            $delta = $choice['delta'] ?? [];

            if (($choice['finish_reason'] ?? null) === 'error') {
                $error = $choice['error'] ?? [];

                yield (new Error(
                    $this->generateEventId(),
                    (string) ($error['code'] ?? 'provider_error'),
                    $error['message'] ?? 'An upstream provider error occurred.',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            if (! $streamStartEmitted) {
                $streamStartEmitted = true;
                $streamModel = $data['model'] ?? $model;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $streamModel,
                    time(),
                ))->withInvocationId($invocationId);
            }

            // Close the reasoning block as soon as visible output begins.
            if ($inReasoning && ((isset($delta['content']) && $delta['content'] !== '') || isset($delta['tool_calls']))) {
                $inReasoning = false;

                yield (new ReasoningEnd(
                    $this->generateEventId(),
                    $reasoningId,
                    time(),
                ))->withInvocationId($invocationId);

                $reasoningId = '';
            }

            // Emit the reasoning the stock gateway drops (OpenRouter's "reasoning"
            // delta; DeepSeek-style "reasoning_content" is also honoured).
            $reasoning = $delta['reasoning'] ?? $delta['reasoning_content'] ?? null;

            if (is_string($reasoning) && $reasoning !== '') {
                if (! $inReasoning) {
                    $inReasoning = true;
                    $reasoningId = $this->generateEventId();

                    yield (new ReasoningStart(
                        $this->generateEventId(),
                        $reasoningId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                yield (new ReasoningDelta(
                    $this->generateEventId(),
                    $reasoningId,
                    $reasoning,
                    time(),
                ))->withInvocationId($invocationId);
            }

            if (isset($delta['content']) && $delta['content'] !== '') {
                if (! $textStartEmitted) {
                    $textStartEmitted = true;

                    yield (new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentText .= $delta['content'];

                yield (new TextDelta(
                    $this->generateEventId(),
                    $messageId,
                    $delta['content'],
                    time(),
                ))->withInvocationId($invocationId);
            }

            if (isset($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tcDelta) {
                    $idx = $tcDelta['index'];

                    if (! isset($pendingToolCalls[$idx])) {
                        $pendingToolCalls[$idx] = [
                            'id' => $tcDelta['id'] ?? '',
                            'name' => $tcDelta['function']['name'] ?? '',
                            'arguments' => '',
                        ];
                    }

                    if (isset($tcDelta['function']['arguments'])) {
                        $pendingToolCalls[$idx]['arguments'] .= $tcDelta['function']['arguments'];
                    }
                }
            }

            if (isset($choice['finish_reason'])) {
                $finishReason = $choice['finish_reason'];
            }

            if (isset($data['usage'])) {
                $usage = $this->extractUsage($data);
                $openRouterCost = $this->extractOpenRouterCost($data) ?? $openRouterCost;
            }
        }

        // Close a reasoning block that never gave way to content/tools.
        if ($inReasoning) {
            yield (new ReasoningEnd(
                $this->generateEventId(),
                $reasoningId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if (filled($pendingToolCalls) && $finishReason === 'tool_calls') {
            foreach (array_values($pendingToolCalls) as $pending) {
                $toolCall = new ToolCall(
                    $pending['id'],
                    $pending['name'],
                    json_decode($pending['arguments'] !== '' ? $pending['arguments'] : '{}', true) ?? [],
                    $pending['id'],
                );

                $toolCalls[] = $toolCall;

                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }
        }

        // Record this round's generation id + exact cost (one push per step;
        // the loop's tool-call rounds recurse into fresh generateStreamStep
        // calls that push their own).
        if ($generationId !== '') {
            Context::push(self::GENERATION_IDS_CONTEXT_KEY, $generationId);
        }

        if ($openRouterCost !== null) {
            Context::push(self::COSTS_CONTEXT_KEY, $openRouterCost);
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason(['finish_reason' => $finishReason ?? '']),
            usage: $usage ?? new Usage(0, 0),
            meta: new Meta($provider->name(), $streamModel),
        );
    }

    /**
     * Extract OpenRouter's reported USD cost for a round, or null when absent
     * or non-positive (a zero/missing cost falls back to estimation paths).
     *
     * @param  array<string, mixed>  $data
     */
    public function extractOpenRouterCost(array $data): ?float
    {
        $cost = $data['usage']['cost'] ?? null;

        if (! is_numeric($cost)) {
            return null;
        }

        $cost = (float) $cost;

        return $cost > 0 ? $cost : null;
    }
}
