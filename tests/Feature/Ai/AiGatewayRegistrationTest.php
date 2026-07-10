<?php

use App\Ai\Gateway\ReasoningOpenRouterGateway;
use Laravel\Ai\AiManager;

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
    expect(config('ai.chat.model'))->toBeString()
        ->and(config('ai.vision.model'))->toBeString()
        ->and(config('ai.embeddings.model'))->toBe('openai/text-embedding-3-small')
        ->and(config('ai.embeddings.dimensions'))->toBe(1536)
        ->and(config('ai.embeddings.driver'))->toBeIn(['fake', 'openrouter']);
});

it('extracts a positive usage cost and rejects missing or zero costs', function () {
    $gateway = new ReasoningOpenRouterGateway(app('events'));

    expect($gateway->extractOpenRouterCost(['usage' => ['cost' => 0.0123]]))->toBe(0.0123)
        ->and($gateway->extractOpenRouterCost(['usage' => ['cost' => '0.5']]))->toBe(0.5)
        ->and($gateway->extractOpenRouterCost(['usage' => ['cost' => 0]]))->toBeNull()
        ->and($gateway->extractOpenRouterCost(['usage' => ['cost' => 'abc']]))->toBeNull()
        ->and($gateway->extractOpenRouterCost([]))->toBeNull();
});
