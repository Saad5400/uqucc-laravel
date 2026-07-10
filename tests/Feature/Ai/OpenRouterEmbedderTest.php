<?php

use App\Ai\Embeddings\OpenRouterEmbedder;
use Illuminate\Support\Facades\Http;

it('sends the expected request shape to the embeddings endpoint', function () {
    config(['ai.providers.openrouter.key' => 'test-key']);

    Http::fake([
        'openrouter.ai/*' => Http::response([
            'data' => [
                ['index' => 0, 'embedding' => [0.1, 0.2]],
                ['index' => 1, 'embedding' => [0.3, 0.4]],
            ],
        ]),
    ]);

    $vectors = new OpenRouterEmbedder('openai/text-embedding-3-small', 2)
        ->embed(['first text', 'second text']);

    expect($vectors)->toBe([[0.1, 0.2], [0.3, 0.4]]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://openrouter.ai/api/v1/embeddings'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request['model'] === 'openai/text-embedding-3-small'
        && $request['input'] === ['first text', 'second text']);
});

it('derives the endpoint from the configured base url', function () {
    config([
        'ai.providers.openrouter.key' => 'test-key',
        'ai.providers.openrouter.url' => 'https://example.test/api/v2/',
    ]);

    Http::fake([
        'example.test/*' => Http::response([
            'data' => [['index' => 0, 'embedding' => [0.5]]],
        ]),
    ]);

    new OpenRouterEmbedder('openai/text-embedding-3-small', 1)->embed(['hi']);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://example.test/api/v2/embeddings');
});

it('re-orders vectors by the response index field', function () {
    config(['ai.providers.openrouter.key' => 'test-key']);

    Http::fake([
        'openrouter.ai/*' => Http::response([
            'data' => [
                ['index' => 1, 'embedding' => [0.3, 0.4]],
                ['index' => 0, 'embedding' => [0.1, 0.2]],
            ],
        ]),
    ]);

    $vectors = new OpenRouterEmbedder('openai/text-embedding-3-small', 2)
        ->embed(['first', 'second']);

    expect($vectors)->toBe([[0.1, 0.2], [0.3, 0.4]]);
});

it('returns an empty array for an empty batch without calling the API', function () {
    Http::fake();

    expect(new OpenRouterEmbedder('openai/text-embedding-3-small', 2)->embed([]))->toBe([]);

    Http::assertNothingSent();
});

it('throws when no API key is configured', function () {
    config(['ai.providers.openrouter.key' => null]);

    Http::fake();

    new OpenRouterEmbedder('openai/text-embedding-3-small', 2)->embed(['text']);
})->throws(RuntimeException::class, 'OPENROUTER_API_KEY');

it('uses an explicitly injected API key over config', function () {
    config(['ai.providers.openrouter.key' => null]);

    Http::fake([
        'openrouter.ai/*' => Http::response([
            'data' => [['index' => 0, 'embedding' => [0.1]]],
        ]),
    ]);

    new OpenRouterEmbedder('openai/text-embedding-3-small', 1, 'injected-key')->embed(['text']);

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer injected-key'));
});

it('throws on a failed response', function () {
    config(['ai.providers.openrouter.key' => 'test-key']);

    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => 'nope'], 500),
    ]);

    new OpenRouterEmbedder('openai/text-embedding-3-small', 2)->embed(['text']);
})->throws(RuntimeException::class, 'failed');

it('throws when the response vector count does not match the input count', function () {
    config(['ai.providers.openrouter.key' => 'test-key']);

    Http::fake([
        'openrouter.ai/*' => Http::response([
            'data' => [['index' => 0, 'embedding' => [0.1, 0.2]]],
        ]),
    ]);

    new OpenRouterEmbedder('openai/text-embedding-3-small', 2)->embed(['one', 'two']);
})->throws(RuntimeException::class, 'shape unexpected');

it('reports its configured dimensions', function () {
    expect(new OpenRouterEmbedder('openai/text-embedding-3-small', 1536)->dimensions())->toBe(1536);
});
