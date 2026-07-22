<?php

namespace App\Ai\Corpus;

/**
 * Splits a corpus document's extracted markdown into numbered, addressable
 * sections so tools can serve a table of contents plus individual sections
 * instead of the whole document.
 *
 * Unlike {@see MarkdownChunker} (which normalizes whitespace for embedding),
 * this preserves the raw markdown of each section verbatim — heading line
 * included — so a fetched section reads exactly as the document does.
 *
 * Guarantees:
 *   - DETERMINISTIC: same markdown → identical section list and numbering.
 *   - COMPLETE: every character of the document lives in exactly one section;
 *     a section longer than $maxChars is split at line (then word) boundaries
 *     into continuation sections, so even a heading-less document is fully
 *     readable section by section.
 *   - 1-BASED numbering shared by the outline and section fetches.
 */
class DocumentSectioner
{
    public function __construct(private readonly int $maxChars = 12000) {}

    /**
     * @return list<DocumentSection>
     */
    public function sections(string $markdown): array
    {
        $sections = [];
        $number = 0;

        foreach ($this->headingBlocks($markdown) as $block) {
            $continuation = false;

            foreach ($this->splitOversized($block['content']) as $part) {
                $sections[] = new DocumentSection(
                    number: ++$number,
                    heading: $block['heading'],
                    level: $block['level'],
                    content: $part,
                    continuation: $continuation,
                );

                $continuation = true;
            }
        }

        return $sections;
    }

    /**
     * Cut the raw markdown at every heading line (# through ######), keeping
     * each heading line inside its own block. Text before the first heading
     * becomes a heading-less block. Blank blocks are dropped.
     *
     * @return list<array{heading: string|null, level: int, content: string}>
     */
    private function headingBlocks(string $markdown): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];

        $blocks = [];
        $heading = null;
        $level = 0;
        $buffer = [];

        $flush = function () use (&$blocks, &$heading, &$level, &$buffer): void {
            $content = trim(implode("\n", $buffer));

            if ($content !== '') {
                $blocks[] = ['heading' => $heading, 'level' => $level, 'content' => $content];
            }

            $buffer = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $matches) === 1) {
                $flush();
                $heading = trim((string) preg_replace('/\s+/u', ' ', $matches[2]));
                $level = strlen($matches[1]);
            }

            $buffer[] = $line;
        }

        $flush();

        return $blocks;
    }

    /**
     * Split one block's content into parts of at most $maxChars, preferring
     * line boundaries and falling back to word boundaries for a single line
     * longer than the cap.
     *
     * @return list<string>
     */
    private function splitOversized(string $content): array
    {
        if (mb_strlen($content) <= $this->maxChars) {
            return [$content];
        }

        $parts = [];
        $buffer = '';

        foreach (preg_split('/\n/', $content) ?: [] as $line) {
            foreach ($this->splitLongLine($line) as $piece) {
                $candidate = $buffer === '' ? $piece : $buffer."\n".$piece;

                if ($buffer !== '' && mb_strlen($candidate) > $this->maxChars) {
                    $parts[] = $buffer;
                    $buffer = $piece;

                    continue;
                }

                $buffer = $candidate;
            }
        }

        if (trim($buffer) !== '') {
            $parts[] = $buffer;
        }

        return $parts === [] ? [$content] : $parts;
    }

    /**
     * @return list<string>
     */
    private function splitLongLine(string $line): array
    {
        if (mb_strlen($line) <= $this->maxChars) {
            return [$line];
        }

        $words = preg_split('/\s+/u', $line, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        $pieces = [];
        $buffer = '';

        foreach ($words as $word) {
            $candidate = $buffer === '' ? $word : $buffer.' '.$word;

            if ($buffer !== '' && mb_strlen($candidate) > $this->maxChars) {
                $pieces[] = $buffer;
                $buffer = $word;

                continue;
            }

            $buffer = $candidate;
        }

        if ($buffer !== '') {
            $pieces[] = $buffer;
        }

        return $pieces === [] ? [$line] : $pieces;
    }
}
