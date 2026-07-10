<?php

namespace App\Ai\Embeddings;

/**
 * The application's embedding entry point.
 *
 * Wraps a concrete {@see Embedder} driver chosen from config, so ingest and
 * any query-time embedding depend on ONE class and the provider stays
 * swappable. Tests bind a {@see FakeEmbedder} via {@see self::fake()} or by
 * swapping this service in the container.
 *
 * Driver selection (config('ai.embeddings.driver'), default 'openrouter'):
 *   - 'fake'       → FakeEmbedder (deterministic, offline)
 *   - 'openrouter' → OpenRouterEmbedder (the real endpoint)
 */
class TextEmbedder implements Embedder
{
    private readonly Embedder $driver;

    /**
     * Container-resolvable: with no explicit driver it self-builds from
     * config, so `app(TextEmbedder::class)` and constructor injection work
     * WITHOUT a service-provider binding. Tests pass a FakeEmbedder
     * explicitly or swap the whole service via the container.
     */
    public function __construct(?Embedder $driver = null)
    {
        $this->driver = $driver ?? self::driverFromConfig();
    }

    /**
     * Build the default driver from config('ai.embeddings.*') with safe
     * fallbacks, so the class constructs even with no config published.
     */
    private static function driverFromConfig(): Embedder
    {
        $dimensions = (int) config('ai.embeddings.dimensions', 1536);
        $driver = (string) config('ai.embeddings.driver', 'openrouter');

        return match ($driver) {
            'fake' => new FakeEmbedder($dimensions),
            default => new OpenRouterEmbedder(
                model: (string) config('ai.embeddings.model', 'openai/text-embedding-3-small'),
                dimensions: $dimensions,
            ),
        };
    }

    /**
     * A fake-backed embedder for tests.
     */
    public static function fake(int $dimensions = 1536): self
    {
        return new self(new FakeEmbedder($dimensions));
    }

    public function embed(array $texts): array
    {
        return $this->driver->embed($texts);
    }

    /**
     * Convenience: embed a single string to one vector.
     *
     * @return list<float>
     */
    public function embedOne(string $text): array
    {
        return $this->embed([$text])[0] ?? [];
    }

    public function dimensions(): int
    {
        return $this->driver->dimensions();
    }
}
