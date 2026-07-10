<?php

namespace App\Models\Corpus;

use Database\Factories\Corpus\CorpusChunkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

/**
 * A retrieval unit: one chunk of a corpus item, its embedding, and an
 * Arabic-normalized copy of the text used by the keyword search leg.
 *
 * The `embedding` cast is connection-aware: on pgsql the column is
 * `vector(N)` and the pgvector `Vector` cast (which also feeds
 * `HasNeighbors`' `<=>` ordering) round-trips it; on the sqlite dev/test
 * fallback the column is a JSON-encoded float list in text — the same cast
 * parses it back, and CorpusRetriever simply never runs the vector leg there.
 *
 * @property int $id
 * @property int $corpus_item_id
 * @property int $chunk_index
 * @property string|null $heading
 * @property string $content
 * @property string $normalized_content
 * @property int|null $token_count
 * @property Vector|null $embedding
 * @property float|null $neighbor_distance Populated by pgvector's
 *                                         nearestNeighbors selectRaw alias during retrieval; not a column.
 */
class CorpusChunk extends Model
{
    /** @use HasFactory<CorpusChunkFactory> */
    use HasFactory, HasNeighbors;

    protected $fillable = [
        'corpus_item_id',
        'chunk_index',
        'heading',
        'content',
        'normalized_content',
        'token_count',
        'embedding',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => Vector::class,
        ];
    }

    /** @return BelongsTo<CorpusItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CorpusItem::class, 'corpus_item_id');
    }

    protected static function newFactory(): CorpusChunkFactory
    {
        return CorpusChunkFactory::new();
    }
}
