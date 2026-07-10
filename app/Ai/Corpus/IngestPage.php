<?php

namespace App\Ai\Corpus;

use App\Ai\Embeddings\TextEmbedder;
use App\Models\Corpus\CorpusChunk;
use App\Models\Corpus\CorpusItem;
use App\Models\Page;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\DB;
use Pgvector\Laravel\Vector;
use Throwable;

/**
 * Ingests one CMS page into the corpus: extract markdown → chunk → embed →
 * replace the item's chunks atomically.
 *
 * IDEMPOTENT by checksum: the sha-256 of the extracted text plus the
 * embedding model/dimensions. Re-running on unchanged content touches no
 * chunks (no re-embedding cost); a retry or re-save REPLACES the chunk set
 * (delete-then-insert in one transaction) instead of duplicating it.
 *
 * SAFE NO-OP when AI search is disabled in settings or the embedding driver
 * is unusable (openrouter without a key) — ingestion never throws for a
 * missing key and never runs against operator intent. Hidden or trashed
 * pages are evicted from the corpus rather than indexed.
 */
class IngestPage
{
    public function __construct(
        private readonly TextEmbedder $embedder,
        private readonly PageContentExtractor $extractor,
        private readonly ArabicTextNormalizer $normalizer,
        private readonly AiSettings $settings,
    ) {}

    /**
     * Whether ingestion may run at all: the search feature toggle (behind the
     * master AI kill switch) plus a usable embedding driver.
     */
    public function isEnabled(): bool
    {
        return $this->settings->isFeatureEnabled('search') && $this->embedderIsConfigured();
    }

    public function ingest(Page $page): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if ($page->hidden || $page->trashed()) {
            $this->forget($page->id);

            return;
        }

        $text = $this->extractor->extract($page);
        $checksum = $this->checksumFor($text);

        $item = CorpusItem::query()->firstOrNew([
            'source_type' => CorpusSourceType::Page,
            'source_id' => $page->id,
        ]);

        if ($item->exists
            && $item->checksum === $checksum
            && $item->status === CorpusItem::STATUS_READY
            && $item->chunks()->exists()) {
            return;
        }

        $item->fill([
            'title' => $page->title,
            'slug' => $page->slug,
            'lang' => 'ar',
            'status' => CorpusItem::STATUS_PROCESSING,
        ])->save();

        try {
            $drafts = MarkdownChunker::fromConfig()->chunk($text);

            $embeddings = $this->embedder->embed(
                array_map(fn (ChunkDraft $draft): string => $draft->embeddingText(), $drafts)
            );

            $rows = $this->rowsFor($item, $drafts, $embeddings);

            DB::transaction(function () use ($item, $rows, $checksum): void {
                $item->chunks()->delete();

                if ($rows !== []) {
                    CorpusChunk::insert($rows);
                }

                $item->update([
                    'checksum' => $checksum,
                    'status' => CorpusItem::STATUS_READY,
                ]);
            });
        } catch (Throwable $exception) {
            $item->update(['status' => CorpusItem::STATUS_FAILED]);

            throw $exception;
        }
    }

    /**
     * Evict a page from the corpus (page deleted, hidden, or gone). Chunks
     * cascade at the database level.
     */
    public function forget(int $pageId): void
    {
        CorpusItem::query()->forPage($pageId)->delete();
    }

    /**
     * @param  list<ChunkDraft>  $drafts
     * @param  list<list<float>>  $embeddings
     * @return list<array<string, mixed>>
     */
    private function rowsFor(CorpusItem $item, array $drafts, array $embeddings): array
    {
        $chunker = MarkdownChunker::fromConfig();
        $now = now();

        $rows = [];

        foreach ($drafts as $index => $draft) {
            $embedding = $embeddings[$index] ?? [];

            $rows[] = [
                'corpus_item_id' => $item->id,
                'chunk_index' => $index,
                'heading' => $draft->heading,
                'content' => $draft->content,
                'normalized_content' => $this->normalizer->normalize(
                    trim(($draft->heading ?? '').' '.$draft->content)
                ),
                'token_count' => $chunker->estimateTokens($draft->content),
                'embedding' => $embedding === [] ? null : (string) new Vector($embedding),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * Deterministic idempotency key. The embedding model and dimensions are
     * part of the digest so switching models forces a re-embed of every page.
     */
    private function checksumFor(string $text): string
    {
        return hash('sha256', implode('|', [
            $text,
            (string) config('ai.embeddings.model', ''),
            (string) config('ai.embeddings.dimensions', 1536),
        ]));
    }

    private function embedderIsConfigured(): bool
    {
        if ((string) config('ai.embeddings.driver', 'openrouter') === 'fake') {
            return true;
        }

        return (string) config('ai.providers.openrouter.key', '') !== '';
    }
}
