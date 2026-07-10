<?php

namespace App\Ai\Embeddings;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The real embedding driver — OpenRouter's OpenAI-compatible embeddings
 * endpoint. POST {base}/embeddings with {"model": "...", "input": [...]}
 * returns `data[].embedding` vectors of `dimensions` floats.
 *
 * Stateless: reads config + key per call, holds no per-request state. This
 * driver only produces vectors; any cost metering belongs to the caller.
 */
class OpenRouterEmbedder implements Embedder
{
    public function __construct(
        private readonly string $model,
        private readonly int $dimensions,
        private readonly ?string $apiKey = null,
    ) {}

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $key = $this->apiKey ?? (string) config('ai.providers.openrouter.key', '');

        if ($key === '') {
            throw new RuntimeException(
                'OPENROUTER_API_KEY is not set — cannot embed text via OpenRouter. '
                .'Set the key, or use the "fake" embeddings driver in non-production.'
            );
        }

        $response = Http::withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->retry(2, 250, throw: false)
            ->post($this->endpoint(), [
                'model' => $this->model,
                'input' => $texts,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "OpenRouter embeddings request failed (HTTP {$response->status()}): "
                .$response->body()
            );
        }

        $rows = $response->json('data');

        if (! is_array($rows) || count($rows) !== count($texts)) {
            throw new RuntimeException(
                'OpenRouter embeddings response shape unexpected: expected '
                .count($texts).' vectors, got '.(is_array($rows) ? count($rows) : 'none').'.'
            );
        }

        // Preserve request order via each row's `index` when present.
        $ordered = [];

        foreach ($rows as $position => $row) {
            $index = is_array($row) && isset($row['index']) ? (int) $row['index'] : $position;
            $embedding = is_array($row) ? ($row['embedding'] ?? null) : null;

            if (! is_array($embedding)) {
                throw new RuntimeException('OpenRouter embeddings response missing an embedding vector.');
            }

            $ordered[$index] = array_map(static fn ($value): float => (float) $value, $embedding);
        }

        ksort($ordered);

        return array_values($ordered);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * The embeddings endpoint derived from the configured OpenRouter base URL.
     */
    private function endpoint(): string
    {
        $base = rtrim((string) config('ai.providers.openrouter.url', 'https://openrouter.ai/api/v1'), '/');

        return $base.'/embeddings';
    }
}
