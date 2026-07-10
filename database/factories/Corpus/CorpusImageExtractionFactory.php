<?php

namespace Database\Factories\Corpus;

use App\Models\Corpus\CorpusImageExtraction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CorpusImageExtraction>
 */
class CorpusImageExtractionFactory extends Factory
{
    protected $model = CorpusImageExtraction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_hash' => hash('sha256', fake()->unique()->uuid()),
            'source_url' => '/storage/'.fake()->unique()->slug(2).'.png',
            'extracted_text' => fake()->sentence(),
            'model' => 'google/gemini-2.5-flash',
            'status' => CorpusImageExtraction::STATUS_EXTRACTED,
        ];
    }

    /**
     * A vision extraction that errored and awaits a retry.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'extracted_text' => null,
            'model' => null,
            'status' => CorpusImageExtraction::STATUS_FAILED,
        ]);
    }

    /**
     * An external-host image the pipeline never fetches.
     */
    public function skipped(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_url' => fake()->imageUrl(),
            'extracted_text' => null,
            'model' => null,
            'status' => CorpusImageExtraction::STATUS_SKIPPED,
        ]);
    }
}
