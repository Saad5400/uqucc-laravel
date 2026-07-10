<?php

namespace App\Ai\Corpus;

/**
 * Splits markdown into overlapping, word-bounded chunks that respect the
 * heading structure.
 *
 * Guarantees the ingest pipeline and its tests depend on:
 *   - DETERMINISTIC: same input + same bounds → byte-identical chunks.
 *   - HEADING-AWARE: the text is first sectioned at every markdown heading
 *     (# through ######); a chunk never spans two sections, and each chunk
 *     carries its section heading as context.
 *   - Never splits mid-word — Arabic and Latin alike chunk on whitespace, so
 *     a word is never cut across two chunks.
 *
 * "Tokens" are whitespace-delimited words: a stable heuristic that slightly
 * over-counts Latin and under-counts agglutinative Arabic, but keeps chunks
 * comfortably inside the embedding model's context without a BPE tokenizer.
 * The defaults (400-word window, 60-word overlap) target roughly 500–1500
 * model tokens per chunk.
 */
class MarkdownChunker
{
    public function __construct(
        private readonly int $maxWords = 400,
        private readonly int $overlapWords = 60,
    ) {}

    /**
     * Build a chunker from config('ai.embeddings.*') with safe fallbacks.
     */
    public static function fromConfig(): self
    {
        return new self(
            maxWords: (int) config('ai.embeddings.chunk_words', 400),
            overlapWords: (int) config('ai.embeddings.chunk_overlap_words', 60),
        );
    }

    /**
     * Chunk markdown into an ordered list of drafts.
     *
     * @return list<ChunkDraft>
     */
    public function chunk(string $markdown): array
    {
        $chunks = [];

        foreach ($this->sections($markdown) as $section) {
            foreach ($this->windows($section['content']) as $window) {
                $chunks[] = new ChunkDraft($section['heading'], $window);
            }
        }

        return $chunks;
    }

    /**
     * Heuristic token count for a chunk (whitespace words).
     */
    public function estimateTokens(string $text): int
    {
        $words = preg_split('/\s+/u', trim($text), flags: PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : count($words);
    }

    /**
     * Cut the markdown at every heading line. Text before the first heading
     * becomes a heading-less section. Empty sections are dropped.
     *
     * @return list<array{heading: string|null, content: string}>
     */
    private function sections(string $markdown): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];

        $sections = [];
        $heading = null;
        $buffer = [];

        $flush = function () use (&$sections, &$heading, &$buffer): void {
            $content = $this->collapse(implode(' ', $buffer));

            if ($content !== '') {
                $sections[] = ['heading' => $heading, 'content' => $content];
            }

            $buffer = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $matches) === 1) {
                $flush();
                $heading = $this->collapse($matches[2]);

                continue;
            }

            $buffer[] = $line;
        }

        $flush();

        return $sections;
    }

    /**
     * Sliding word window with overlap over one section's content.
     *
     * @return list<string>
     */
    private function windows(string $content): array
    {
        $words = preg_split('/\s+/u', $content, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return [];
        }

        $total = count($words);

        $overlap = max(0, min($this->overlapWords, $this->maxWords - 1));

        $windows = [];
        $start = 0;

        while ($start < $total) {
            $end = min($start + $this->maxWords, $total);
            $windows[] = implode(' ', array_slice($words, $start, $end - $start));

            if ($end >= $total) {
                break;
            }

            $start += ($this->maxWords - $overlap);
        }

        return $windows;
    }

    /**
     * Collapse all whitespace runs (incl. NBSP, common in pasted Arabic) to a
     * single space so identical sources always yield identical chunks.
     */
    private function collapse(string $text): string
    {
        $collapsed = preg_replace('/[\s\x{00A0}]+/u', ' ', $text);

        return trim($collapsed ?? '');
    }
}
