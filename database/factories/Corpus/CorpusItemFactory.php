<?php

namespace Database\Factories\Corpus;

use App\Ai\Corpus\CorpusSourceType;
use App\Models\Corpus\CorpusItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CorpusItem>
 */
class CorpusItemFactory extends Factory
{
    protected $model = CorpusItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_type' => CorpusSourceType::Page,
            'source_id' => fake()->unique()->numberBetween(1, 100000),
            'title' => fake()->sentence(3),
            'slug' => '/'.fake()->unique()->slug(3),
            'lang' => 'ar',
            'status' => CorpusItem::STATUS_READY,
            'enabled' => true,
            'checksum' => hash('sha256', fake()->unique()->uuid()),
            'meta' => null,
        ];
    }

    /**
     * An item whose ingestion has not started yet.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CorpusItem::STATUS_PENDING,
            'checksum' => null,
        ]);
    }

    /**
     * A ready item the admin has switched off — kept whole but excluded from
     * every AI retrieval path.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => false,
        ]);
    }
}
