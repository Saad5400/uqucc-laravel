<?php

namespace App\Ai\Corpus;

/**
 * One addressable section of a corpus document's extracted markdown: the raw
 * section text (heading line included), its 1-based position, and whether it
 * is a size-split continuation of the previous section rather than a real
 * heading boundary.
 */
final readonly class DocumentSection
{
    public function __construct(
        public int $number,
        public ?string $heading,
        public int $level,
        public string $content,
        public bool $continuation = false,
    ) {}

    /**
     * Heuristic word count (whitespace-delimited), mirroring the chunker's
     * token estimate so outline sizes and chunk sizes read consistently.
     */
    public function wordCount(): int
    {
        $words = preg_split('/\s+/u', trim($this->content), flags: PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : count($words);
    }
}
