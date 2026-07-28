<?php

namespace App\Ai\Corpus;

use App\Models\Corpus\CorpusDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Renders retrieval hits as the numbered snippet list the model reads.
 *
 * Written once and shared by every surface that hands the model corpus
 * snippets — the search_content tool and the pre-fetched evidence
 * {@see \App\Ai\Chat\CategoryContext} injects — so a snippet looks the same
 * whether the model asked for it or was handed it, and the citation markers
 * {@see \App\Ai\Chat\CitationExtractor} parses keep one definition.
 */
class CorpusResultFormatter
{
    private const SNIPPET_LENGTH = 400;

    /**
     * @param  Collection<int, CorpusSearchResult>  $results
     */
    public function render(Collection $results): string
    {
        $documentUrls = $this->documentUrlsFor($results);

        return $results
            ->values()
            ->map(fn (CorpusSearchResult $result, int $index): string => $this->line($result, $index, $documentUrls))
            ->implode("\n\n");
    }

    /**
     * @param  array<int, string>  $documentUrls
     */
    private function line(CorpusSearchResult $result, int $index, array $documentUrls): string
    {
        $heading = $result->heading !== null && $result->heading !== '' ? " — {$result->heading}" : '';

        $slug = match (true) {
            $result->slug !== null && $result->slug !== '' => " (slug: {$result->slug})",
            $result->sourceType === CorpusSourceType::Document => " (document: {$result->sourceId})",
            default => '',
        };

        // The freshness date and document link live on their own indented
        // lines: the result line must keep ENDING with "(slug: …)" —
        // CitationExtractor parses that stable marker.
        $updated = $result->sourceUpdatedAt !== null
            ? "\n   آخر تحديث: ".$result->sourceUpdatedAt->toDateString()
            : '';

        $documentUrl = $result->sourceType === CorpusSourceType::Document
            ? "\n   رابط المستند (المصدر): ".($documentUrls[$result->sourceId] ?? route('documents.show', $result->sourceId))
            : '';

        return sprintf(
            "%d. %s%s%s%s%s\n   %s",
            $index + 1,
            $result->title,
            $heading,
            $slug,
            $updated,
            $documentUrl,
            Str::limit(trim($result->content), self::SNIPPET_LENGTH),
        );
    }

    /**
     * Resolve each document-sourced result to its citation URL in one query,
     * honoring any per-document override, so the loop above never triggers an
     * N+1 nor duplicates the model's override-or-default logic.
     *
     * @param  Collection<int, CorpusSearchResult>  $results
     * @return array<int, string> Keyed by document id.
     */
    private function documentUrlsFor(Collection $results): array
    {
        $documentIds = $results
            ->filter(fn (CorpusSearchResult $result): bool => $result->sourceType === CorpusSourceType::Document)
            ->map(fn (CorpusSearchResult $result): int => $result->sourceId)
            ->unique();

        if ($documentIds->isEmpty()) {
            return [];
        }

        return CorpusDocument::query()
            ->whereKey($documentIds->all())
            ->get()
            ->mapWithKeys(fn (CorpusDocument $document): array => [$document->id => $document->referenceUrl()])
            ->all();
    }
}
