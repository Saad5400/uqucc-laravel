<?php

use App\Ai\Embeddings\Embedder;
use App\Ai\Embeddings\FakeEmbedder;
use App\Ai\Embeddings\TextEmbedder;
use Illuminate\Support\Facades\Http;

it('uses the fake driver when configured, without any HTTP call', function () {
    config([
        'ai.embeddings.driver' => 'fake',
        'ai.embeddings.dimensions' => 64,
    ]);

    Http::fake();

    $embedder = app(TextEmbedder::class);

    $vector = $embedder->embedOne('hello world');

    expect($embedder->dimensions())->toBe(64)
        ->and($vector)->toHaveCount(64);

    Http::assertNothingSent();
});

it('uses the openrouter driver when configured', function () {
    config([
        'ai.embeddings.driver' => 'openrouter',
        'ai.embeddings.model' => 'openai/text-embedding-3-small',
        'ai.embeddings.dimensions' => 3,
        'ai.providers.openrouter.key' => 'test-key',
    ]);

    Http::fake([
        'openrouter.ai/*' => Http::response([
            'data' => [['index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
        ]),
    ]);

    $vector = app(TextEmbedder::class)->embedOne('hello');

    expect($vector)->toBe([0.1, 0.2, 0.3]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://openrouter.ai/api/v1/embeddings'
        && $request['model'] === 'openai/text-embedding-3-small');
});

it('falls back to the openrouter driver for an unknown driver name', function () {
    config([
        'ai.embeddings.driver' => 'not-a-real-driver',
        'ai.embeddings.dimensions' => 3,
        'ai.providers.openrouter.key' => 'test-key',
    ]);

    Http::fake([
        'openrouter.ai/*' => Http::response([
            'data' => [['index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
        ]),
    ]);

    app(TextEmbedder::class)->embedOne('hello');

    Http::assertSentCount(1);
});

it('constructs with safe defaults when no embeddings config is present', function () {
    config([
        'ai.embeddings' => null,
    ]);

    expect(app(TextEmbedder::class)->dimensions())->toBe(1536);
});

it('provides a deterministic fake helper for tests', function () {
    Http::fake();

    $embedder = TextEmbedder::fake(32);

    expect($embedder)->toBeInstanceOf(Embedder::class)
        ->and($embedder->dimensions())->toBe(32)
        ->and($embedder->embedOne('same text'))->toBe($embedder->embedOne('same text'));

    Http::assertNothingSent();
});

it('accepts an explicit driver via the constructor', function () {
    config(['ai.embeddings.driver' => 'openrouter']);

    $embedder = new TextEmbedder(new FakeEmbedder(16));

    expect($embedder->dimensions())->toBe(16);
});

it('returns an empty vector from embedOne for empty batch results', function () {
    expect(TextEmbedder::fake(8)->embedOne(''))->toHaveCount(8);
});
