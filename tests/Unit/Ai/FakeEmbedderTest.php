<?php

use App\Ai\Embeddings\FakeEmbedder;

it('returns one vector per input, index-aligned', function () {
    $embedder = new FakeEmbedder(64);

    $vectors = $embedder->embed(['alpha', 'beta', 'gamma']);

    expect($vectors)->toHaveCount(3)
        ->and($vectors[0])->toHaveCount(64)
        ->and($vectors[1])->toHaveCount(64)
        ->and($vectors[2])->toHaveCount(64);
});

it('is deterministic for the same text', function () {
    $embedder = new FakeEmbedder(128);

    $first = $embedder->embed(['hello world'])[0];
    $second = $embedder->embed(['hello world'])[0];

    expect($first)->toBe($second);
});

it('produces different vectors for different texts', function () {
    $embedder = new FakeEmbedder(128);

    [$a, $b] = $embedder->embed(['completely different', 'unrelated words entirely']);

    expect($a)->not->toBe($b);
});

it('reports its configured dimensions and defaults to 1536', function () {
    expect(new FakeEmbedder(256)->dimensions())->toBe(256)
        ->and(new FakeEmbedder()->dimensions())->toBe(1536);
});

it('returns unit vectors for non-empty text', function () {
    $vector = new FakeEmbedder(64)->embed(['some text here'])[0];

    $norm = sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $vector)));

    expect($norm)->toEqualWithDelta(1.0, 1e-9);
});

it('returns a zero vector for empty text', function () {
    $vector = new FakeEmbedder(32)->embed([''])[0];

    expect(array_sum($vector))->toBe(0.0);
});

it('ranks a token-sharing paraphrase closer than an unrelated string', function () {
    $embedder = new FakeEmbedder(256);

    [$query, $paraphrase, $unrelated] = $embedder->embed([
        'club programming workshop schedule',
        'the programming workshop schedule for the club',
        'quantum entanglement in superconductors',
    ]);

    $dot = fn (array $a, array $b): float => array_sum(array_map(
        fn (float $x, float $y): float => $x * $y,
        $a,
        $b,
    ));

    expect($dot($query, $paraphrase))->toBeGreaterThan($dot($query, $unrelated));
});
