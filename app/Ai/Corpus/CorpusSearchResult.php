<?php

namespace App\Ai\Corpus;

/**
 * One retrieval hit: the chunk text plus enough source context (page slug,
 * title, section heading) for a caller to cite or link it. `score` is the
 * reciprocal-rank-fusion score — comparable within one search() call, not
 * across calls.
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
    ) {}

    /**
     * @return array{chunk_id: int, source_type: string, source_id: int, title: string, slug: string|null, heading: string|null, content: string, score: float}
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
        ];
    }
}
