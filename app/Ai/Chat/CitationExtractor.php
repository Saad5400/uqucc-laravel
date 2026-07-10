<?php

namespace App\Ai\Chat;

use App\Models\Page;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * Derives the citation list of an assistant turn from the content tools it
 * actually consulted: pages read in full via get_page, and the snippets
 * search_content returned. Works on live streamed tool results and on the
 * tool_results JSON the conversation store persisted, so the SSE `citations`
 * event and the history endpoint always agree.
 *
 * Parsing leans on the tools' own stable output markers (get_page's trailing
 * "slug:" line, search_content's "(slug: …)" result lines) rather than on
 * model output, so a citation is never hallucinated.
 */
class CitationExtractor
{
    /** Keep the citation strip readable — full pages first, then snippets. */
    private const MAX_CITATIONS = 6;

    /**
     * @param  list<ToolResult>  $toolResults
     * @return list<array{title: string, slug: string, heading: string|null}>
     */
    public function extract(array $toolResults): array
    {
        $citations = [];

        foreach ($toolResults as $result) {
            if ($result->name === 'get_page') {
                $citations = [...$citations, ...$this->fromGetPage($result)];
            }
        }

        foreach ($toolResults as $result) {
            if ($result->name === 'search_content') {
                $citations = [...$citations, ...$this->fromSearchContent($result)];
            }
        }

        $unique = [];

        foreach ($citations as $citation) {
            $key = $citation['slug'].'|'.($citation['heading'] ?? '');

            if (! isset($unique[$key])) {
                $unique[$key] = $citation;
            }
        }

        return array_slice(array_values($unique), 0, self::MAX_CITATIONS);
    }

    /**
     * As extract(), but from the tool_results arrays a stored conversation
     * message carries.
     *
     * @param  array<int, array<string, mixed>>  $storedToolResults
     * @return list<array{title: string, slug: string, heading: string|null}>
     */
    public function extractFromStored(array $storedToolResults): array
    {
        $results = [];

        foreach ($storedToolResults as $stored) {
            if (is_array($stored) && isset($stored['id'], $stored['name'], $stored['arguments'])) {
                $results[] = ToolResult::fromArray($stored);
            }
        }

        return $this->extract($results);
    }

    /**
     * A successful get_page result ends with "---\nslug: {slug}"; resolve the
     * page title from the database (the tool only serves visible pages).
     *
     * @return list<array{title: string, slug: string, heading: string|null}>
     */
    private function fromGetPage(ToolResult $result): array
    {
        $text = $this->resultText($result);

        if ($text === null || preg_match('/\n---\nslug: (\S+)\s*$/u', $text, $matches) !== 1) {
            return [];
        }

        $title = Page::query()
            ->visible()
            ->where('slug', $matches[1])
            ->value('title');

        if ($title === null) {
            return [];
        }

        return [['title' => (string) $title, 'slug' => $matches[1], 'heading' => null]];
    }

    /**
     * search_content result lines look like "1. Title — Heading (slug: /x)"
     * (the heading part is optional).
     *
     * @return list<array{title: string, slug: string, heading: string|null}>
     */
    private function fromSearchContent(ToolResult $result): array
    {
        $text = $this->resultText($result);

        if ($text === null) {
            return [];
        }

        preg_match_all('/^\d+\.\s+(.+?)\s+\(slug:\s*([^)\s]+)\)\s*$/mu', $text, $matches, PREG_SET_ORDER);

        $citations = [];

        foreach ($matches as $match) {
            [$title, $heading] = array_pad(explode(' — ', $match[1], 2), 2, null);

            $citations[] = [
                'title' => trim((string) $title),
                'slug' => $match[2],
                'heading' => $heading !== null ? trim($heading) : null,
            ];
        }

        return $citations;
    }

    private function resultText(ToolResult $result): ?string
    {
        $text = $result->result;

        if ($text instanceof \Stringable) {
            $text = (string) $text;
        }

        return is_string($text) ? $text : null;
    }
}
