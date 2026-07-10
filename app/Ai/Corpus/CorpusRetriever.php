<?php

namespace App\Ai\Corpus;

use App\Ai\Embeddings\TextEmbedder;
use App\Models\Corpus\CorpusChunk;
use App\Models\Corpus\CorpusItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Pgvector\Laravel\Distance;
use Pgvector\Laravel\Vector;
use Throwable;

/**
 * Hybrid retrieval over the corpus — the read side the AI search endpoint
 * and MCP tools consume.
 *
 * Two legs, merged by reciprocal rank fusion (score = Σ 1/(60 + rank)):
 *
 *  - VECTOR (pgsql only): embed the query via TextEmbedder and rank by
 *    pgvector cosine (`<=>`, HNSW-indexed). Skipped entirely off pgsql or
 *    when the embedding driver is unusable — the driver check happens here,
 *    so local sqlite dev and tests never need pgvector or a network call.
 *
 *  - KEYWORD (all drivers): the query is Arabic-normalized with the SAME
 *    ArabicTextNormalizer used at index time and matched with LIKE against
 *    corpus_chunks.normalized_content (both sides lowercased + folded, so
 *    plain LIKE suffices on pgsql and sqlite alike), ranked by how many
 *    distinct query tokens a chunk contains, then by total occurrences.
 *
 * Only chunks of `ready` items are searched, so a half-ingested page never
 * surfaces. Degrades gracefully: if the embedding call fails at query time
 * the keyword leg still answers.
 */
class CorpusRetriever
{
    private const RRF_K = 60;

    private const KEYWORD_SCAN_CAP = 200;

    public function __construct(
        private readonly TextEmbedder $embedder,
        private readonly ArabicTextNormalizer $normalizer,
    ) {}

    /**
     * Retrieve the chunks most relevant to $query, best first.
     *
     * @return Collection<int, CorpusSearchResult>
     */
    public function search(string $query, int $limit = 8): Collection
    {
        $limit = max(1, $limit);
        $query = trim($query);

        if ($query === '') {
            return new Collection;
        }

        $pool = max($limit * 4, 20);

        $legs = array_values(array_filter([
            $this->keywordLeg($query, $pool),
            $this->vectorLeg($query, $pool),
        ], fn (Collection $leg): bool => $leg->isNotEmpty()));

        return $this->fuse($legs)
            ->take($limit)
            ->map(fn (CorpusChunk $chunk): CorpusSearchResult => $this->resultFor($chunk))
            ->values();
    }

    /**
     * Keyword leg: normalized-token LIKE match, ranked in PHP over a capped
     * candidate set (the corpus is CMS-page sized; a trigram index is the
     * upgrade path if it ever outgrows the cap).
     *
     * @return Collection<int, CorpusChunk>
     */
    private function keywordLeg(string $query, int $pool): Collection
    {
        $tokens = $this->normalizer->tokenize($query);

        if ($tokens === []) {
            return new Collection;
        }

        $candidates = $this->readyChunks()
            ->where(function (Builder $builder) use ($tokens): void {
                foreach ($tokens as $token) {
                    $builder->orWhere('normalized_content', 'like', '%'.$token.'%');
                }
            })
            ->limit(self::KEYWORD_SCAN_CAP)
            ->get();

        return $candidates
            ->map(function (CorpusChunk $chunk) use ($tokens): array {
                $matched = 0;
                $occurrences = 0;

                foreach ($tokens as $token) {
                    $count = mb_substr_count($chunk->normalized_content, $token);

                    if ($count > 0) {
                        $matched++;
                        $occurrences += $count;
                    }
                }

                return ['chunk' => $chunk, 'matched' => $matched, 'occurrences' => $occurrences];
            })
            ->sortBy([['matched', 'desc'], ['occurrences', 'desc'], ['chunk.id', 'asc']])
            ->take($pool)
            ->map(fn (array $row): CorpusChunk => $row['chunk'])
            ->values();
    }

    /**
     * Vector leg: pgvector cosine ordering, pgsql only. Returns empty — never
     * throws — when off pgsql, when the driver is unconfigured, or when the
     * embedding call itself fails, so keyword results always survive.
     *
     * @return Collection<int, CorpusChunk>
     */
    private function vectorLeg(string $query, int $pool): Collection
    {
        if (! $this->onPostgres() || ! $this->embedderIsConfigured()) {
            return new Collection;
        }

        try {
            $embedding = $this->embedder->embedOne($query);
        } catch (Throwable) {
            return new Collection;
        }

        if ($embedding === []) {
            return new Collection;
        }

        return $this->readyChunks()
            ->nearestNeighbors('embedding', new Vector($embedding), Distance::Cosine)
            ->limit($pool)
            ->get();
    }

    /**
     * Reciprocal rank fusion across the legs: each leg contributes
     * 1/(60 + rank) per chunk, so a chunk ranked well by BOTH legs beats a
     * chunk that tops only one.
     *
     * @param  list<Collection<int, CorpusChunk>>  $legs
     * @return Collection<int, CorpusChunk> Chunks with `search_score` set, best first.
     */
    private function fuse(array $legs): Collection
    {
        /** @var array<int, array{chunk: CorpusChunk, score: float}> $scored */
        $scored = [];

        foreach ($legs as $leg) {
            foreach ($leg->values() as $rank => $chunk) {
                $id = (int) $chunk->getKey();

                $scored[$id] ??= ['chunk' => $chunk, 'score' => 0.0];
                $scored[$id]['score'] += 1.0 / (self::RRF_K + $rank + 1);
            }
        }

        return (new Collection($scored))
            ->sortByDesc('score')
            ->map(function (array $row): CorpusChunk {
                $row['chunk']->setAttribute('search_score', $row['score']);

                return $row['chunk'];
            })
            ->values();
    }

    private function resultFor(CorpusChunk $chunk): CorpusSearchResult
    {
        $chunk->loadMissing('item');

        /** @var CorpusItem $item */
        $item = $chunk->item;

        return new CorpusSearchResult(
            chunkId: (int) $chunk->id,
            sourceType: $item->source_type,
            sourceId: (int) $item->source_id,
            title: $item->title,
            slug: $item->slug,
            heading: $chunk->heading,
            content: $chunk->content,
            score: (float) $chunk->getAttribute('search_score'),
        );
    }

    /**
     * @return Builder<CorpusChunk>
     */
    private function readyChunks(): Builder
    {
        return CorpusChunk::query()
            ->with('item')
            ->whereHas('item', fn (Builder $items) => $items->where('status', CorpusItem::STATUS_READY));
    }

    private function onPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Mirrors IngestPage's driver check: "fake" always works; the real driver
     * needs an OpenRouter key.
     */
    private function embedderIsConfigured(): bool
    {
        if ((string) config('ai.embeddings.driver', 'openrouter') === 'fake') {
            return true;
        }

        return (string) config('ai.providers.openrouter.key', '') !== '';
    }
}
