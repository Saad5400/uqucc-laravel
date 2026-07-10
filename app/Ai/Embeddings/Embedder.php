<?php

namespace App\Ai\Embeddings;

/**
 * A pluggable embedding backend.
 *
 * Callers never name a provider directly: they depend on this interface, and
 * the concrete driver (real OpenRouter vs deterministic fake) is chosen in
 * {@see TextEmbedder} from config. Swapping to another embeddings provider
 * later is a new driver behind the same contract — no caller changes.
 */
interface Embedder
{
    /**
     * Embed a batch of strings into vectors, index-aligned with the input.
     *
     * @param  list<string>  $texts
     * @return list<list<float>> One float vector per input string, same order.
     */
    public function embed(array $texts): array;

    /**
     * The vector dimension every returned embedding has.
     */
    public function dimensions(): int;
}
