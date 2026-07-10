<?php

namespace App\Ai\Embeddings;

/**
 * Deterministic, offline embedder for tests and keyless local development.
 *
 * Given the same text and dimension it always returns the same unit vector,
 * so ingest is reproducible, retrieval ordering is stable and assertable
 * without a network call, and a paraphrase sharing tokens sits closer (in
 * cosine) than an unrelated string — enough to exercise ranking paths
 * deterministically.
 *
 * The vector is built from a bag-of-token hash: each whitespace token adds
 * weight to a few deterministically-chosen dimensions, then the vector is
 * L2-normalised. This gives token-overlap-sensitive cosine similarity
 * (shared words → higher similarity) without any model.
 */
class FakeEmbedder implements Embedder
{
    public function __construct(private readonly int $dimensions = 1536) {}

    public function embed(array $texts): array
    {
        return array_map(fn (string $text): array => $this->vectorFor($text), $texts);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @return list<float>
     */
    private function vectorFor(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);

        $tokens = preg_split('/\s+/u', mb_strtolower(trim($text)), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            // Two stable hash-derived dimensions per token so distinct tokens
            // rarely fully collide, and a token always lands the same way.
            $h1 = crc32($token);
            $h2 = crc32('salt:'.$token);

            $vector[$h1 % $this->dimensions] += 1.0;
            $vector[$h2 % $this->dimensions] += 0.5;
        }

        return $this->normalise($vector);
    }

    /**
     * L2-normalise to a unit vector so cosine similarity == dot product and
     * is bounded in [-1, 1]. A zero vector (empty text) stays zero.
     *
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function normalise(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $vector)));

        if ($norm === 0.0) {
            return $vector;
        }

        return array_map(fn (float $v): float => $v / $norm, $vector);
    }
}
