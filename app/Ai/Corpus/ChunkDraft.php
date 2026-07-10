<?php

namespace App\Ai\Corpus;

/**
 * One chunk produced by the chunker, before embedding and persistence:
 * the chunk body plus the markdown heading it sits under (for context and
 * display in search results).
 */
final readonly class ChunkDraft
{
    public function __construct(
        public ?string $heading,
        public string $content,
    ) {}

    /**
     * The text handed to the embedding model — heading prepended so a chunk
     * stays semantically anchored to its section even when the body alone is
     * ambiguous.
     */
    public function embeddingText(): string
    {
        if ($this->heading === null || $this->heading === '') {
            return $this->content;
        }

        return $this->heading."\n\n".$this->content;
    }
}
