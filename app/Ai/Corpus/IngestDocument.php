<?php

namespace App\Ai\Corpus;

use App\Ai\Embeddings\TextEmbedder;
use App\Models\Corpus\CorpusChunk;
use App\Models\Corpus\CorpusDocument;
use App\Models\Corpus\CorpusItem;
use App\Settings\AiSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Pgvector\Laravel\Vector;
use Throwable;

/**
 * Ingests one uploaded document's extracted markdown into the corpus:
 * chunk → embed → replace the item's chunks atomically. The document-source
 * twin of {@see IngestPage} — same gates, same idempotency contract.
 *
 * IDEMPOTENT by checksum: the sha-256 of the extracted markdown plus the
 * embedding model/dimensions. Re-ingesting unchanged text touches no chunks;
 * changed text (a re-extraction or an admin edit) REPLACES the chunk set in
 * one transaction instead of duplicating it.
 *
 * SAFE NO-OP when AI search is disabled in settings or the embedding driver
 * is unusable — extraction still completes and the markdown is kept, so the
 * admin can re-ingest from the panel once search is switched on. A document
 * without extracted text is evicted rather than indexed.
 */
class IngestDocument
{
    public function __construct(
        private readonly TextEmbedder $embedder,
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

    public function ingest(CorpusDocument $document): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $text = trim((string) $document->extracted_markdown);

        if ($text === '') {
            $this->forget($document->id);

            return;
        }

        $checksum = $this->checksumFor($text);

        $item = CorpusItem::query()->firstOrNew([
            'source_type' => CorpusSourceType::Document,
            'source_id' => $document->id,
        ]);

        if ($item->exists
            && $item->checksum === $checksum
            && $item->status === CorpusItem::STATUS_READY
            && $item->chunks()->exists()) {
            return;
        }

        $item->fill([
            'title' => $document->title,
            'slug' => null,
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
     * Evict a document from the corpus (deleted or emptied). Chunks cascade
     * at the database level.
     */
    public function forget(int $documentId): void
    {
        $this->itemQuery($documentId)->delete();
    }

    /**
     * @return Builder<CorpusItem>
     */
    private function itemQuery(int $documentId): Builder
    {
        return CorpusItem::query()
            ->where('source_type', CorpusSourceType::Document)
            ->where('source_id', $documentId);
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
     * part of the digest so switching models forces a re-embed.
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
