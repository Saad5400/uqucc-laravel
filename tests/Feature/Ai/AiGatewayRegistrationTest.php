<?php

use Laravel\Ai\AiManager;
use Laravel\Ai\Exceptions\AiException;
use Saad\AiKit\Catalog\Catalog;
use Saad\AiKit\Gateway\ReasoningOpenRouterGateway;
use Saad\AiKit\Gateway\SpendCollector;

it('resolves the openrouter text provider with the reasoning gateway', function () {
    $provider = app(AiManager::class)->textProvider('openrouter');

    expect($provider->textGateway())->toBeInstanceOf(ReasoningOpenRouterGateway::class);
});

it('defaults the ai provider config to openrouter', function () {
    expect(config('ai.default'))->toBe('openrouter')
        ->and(config('ai.default_for_embeddings'))->toBe('openrouter')
        ->and(config('ai.providers.openrouter.driver'))->toBe('openrouter')
        ->and(config('ai.providers.openrouter.url'))->toBe('https://openrouter.ai/api/v1');
});

it('exposes per-task model config keys', function () {
    // Vision is null here on purpose — the slug is inherited from the kit
    // (#26b), not pinned again by this app. Embeddings stay app-level.
    expect(config('ai.vision.model'))->toBeNull()
        ->and(config('ai.embeddings.model'))->toBe('openai/text-embedding-3-small')
        ->and(config('ai.embeddings.dimensions'))->toBe(1536)
        ->and(config('ai.embeddings.driver'))->toBeIn(['fake', 'openrouter']);
});

it('inherits the chat and vision models from the kit instead of pinning its own', function () {
    // `ai.chat.model` / `ai.vision.model` are override hooks (AI_CHAT_MODEL,
    // AI_VISION_MODEL) and nothing more — the fleet's shared defaults live in
    // the kit (ai-kit DECISIONS.md #26), so uqucc carries no second copy of a
    // slug to drift.
    expect(config('ai.chat.model'))->toBeNull()
        ->and(config('ai.vision.model'))->toBeNull()
        ->and(config('ai-kit.chat.model'))->toBe('deepseek/deepseek-v4-flash')
        ->and(app(Catalog::class)->chatModel())->toBe('deepseek/deepseek-v4-flash')
        ->and(app(Catalog::class)->visionModel())->toBe('google/gemini-2.5-flash-lite');
});

it('turns an empty or invalid OpenRouter body into a clean AiException, not a TypeError', function () {
    $gateway = new class(app('events'), app(SpendCollector::class)) extends ReasoningOpenRouterGateway
    {
        /** @param  array<string, mixed>|null  $data */
        public function validate(?array $data): void
        {
            $this->validateTextResponse($data);
        }
    };

    // A 2xx whose body decodes to null used to fatal with a TypeError.
    expect(fn () => $gateway->validate(null))->toThrow(AiException::class);
    expect(fn () => $gateway->validate([]))->toThrow(AiException::class);
    expect(fn () => $gateway->validate(['error' => ['message' => 'boom']]))->toThrow(AiException::class);

    // A well-formed body still passes straight through.
    $gateway->validate(['choices' => [['message' => ['content' => 'hi']]]]);
});

it('extracts a positive usage cost and rejects missing or zero costs', function () {
    $gateway = app(ReasoningOpenRouterGateway::class);

    expect($gateway->extractOpenRouterCost(['usage' => ['cost' => 0.0123]]))->toBe(0.0123)
        ->and($gateway->extractOpenRouterCost(['usage' => ['cost' => '0.5']]))->toBe(0.5)
        ->and($gateway->extractOpenRouterCost(['usage' => ['cost' => 0]]))->toBeNull()
        ->and($gateway->extractOpenRouterCost(['usage' => ['cost' => 'abc']]))->toBeNull()
        ->and($gateway->extractOpenRouterCost([]))->toBeNull();
});
