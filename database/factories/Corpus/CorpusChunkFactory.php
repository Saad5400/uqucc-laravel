<?php

namespace Database\Factories\Corpus;

use App\Ai\Corpus\ArabicTextNormalizer;
use App\Ai\Embeddings\FakeEmbedder;
use App\Models\Corpus\CorpusChunk;
use App\Models\Corpus\CorpusItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Pgvector\Laravel\Vector;

/**
 * @extends Factory<CorpusChunk>
 */
class CorpusChunkFactory extends Factory
{
    protected $model = CorpusChunk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = fake()->paragraph();

        return [
            'corpus_item_id' => CorpusItem::factory(),
            'chunk_index' => 0,
            'heading' => null,
            'content' => $content,
            'normalized_content' => app(ArabicTextNormalizer::class)->normalize($content),
            'token_count' => str_word_count($content),
            'embedding' => null,
        ];
    }

    /**
     * A chunk with specific text, keeping normalized_content consistent.
     */
    public function withContent(string $content, ?string $heading = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'heading' => $heading,
            'content' => $content,
            'normalized_content' => app(ArabicTextNormalizer::class)->normalize(
                trim(($heading ?? '').' '.$content)
            ),
        ]);
    }

    /**
     * A chunk carrying a deterministic fake embedding of its content.
     */
    public function embedded(int $dimensions = 1536): static
    {
        return $this->state(function (array $attributes) use ($dimensions): array {
            $vector = (new FakeEmbedder($dimensions))->embed([$attributes['content']])[0];

            return [
                'embedding' => new Vector($vector),
            ];
        });
    }
}
