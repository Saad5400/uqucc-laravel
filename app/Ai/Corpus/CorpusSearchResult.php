<?php

namespace App\Ai\Corpus;

use Carbon\CarbonInterface;

/**
 * One retrieval hit: the chunk text plus enough source context (page slug,
 * title, section heading, source freshness) for a caller to cite, link, or
 * age-flag it. `score` is the reciprocal-rank-fusion score — comparable
 * within one search() call, not across calls. `sourceUpdatedAt` is when the
 * SOURCE (page/document) was last updated, null for items ingested before
 * the freshness column existed.
 */
final readonly class CorpusSearchResult
{
    public function __construct(
        public int $chunkId,
        public CorpusSourceType $sourceType,
        public int $sourceId,
        public string $title,
        public ?string $slug,
        public ?string $heading,
        public string $content,
        public float $score,
        public ?CarbonInterface $sourceUpdatedAt = null,
    ) {}

    /**
     * @return array{chunk_id: int, source_type: string, source_id: int, title: string, slug: string|null, heading: string|null, content: string, score: float, source_updated_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'source_type' => $this->sourceType->value,
            'source_id' => $this->sourceId,
            'title' => $this->title,
            'slug' => $this->slug,
            'heading' => $this->heading,
            'content' => $this->content,
            'score' => $this->score,
            'source_updated_at' => $this->sourceUpdatedAt?->toDateString(),
        ];
    }
}
