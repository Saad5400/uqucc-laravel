<?php

namespace App\Models\Corpus;

use App\Ai\Corpus\CorpusSourceType;
use App\Models\Page;
use Database\Factories\Corpus\CorpusItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ingested knowledge source (a CMS page today, an uploaded document
 * later), identified by (source_type, source_id). `checksum` is the
 * sha-256 of the extracted text plus embedding model — the ingest
 * idempotency key.
 *
 * @property int $id
 * @property CorpusSourceType $source_type
 * @property int $source_id
 * @property string $title
 * @property string|null $slug
 * @property string|null $lang
 * @property string $status
 * @property string|null $checksum
 * @property array<string, mixed>|null $meta
 * @property \Illuminate\Support\Carbon|null $source_updated_at
 */
class CorpusItem extends Model
{
    /** @use HasFactory<CorpusItemFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'source_type',
        'source_id',
        'title',
        'slug',
        'lang',
        'status',
        'checksum',
        'meta',
        'source_updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => CorpusSourceType::class,
            'meta' => 'array',
            'source_updated_at' => 'datetime',
        ];
    }

    /** @return HasMany<CorpusChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(CorpusChunk::class);
    }

    /**
     * Items whose chunks are fully ingested and safe to retrieve from.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_READY);
    }

    /**
     * The item representing one CMS page, if it has been ingested.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPage(Builder $query, Page|int $page): Builder
    {
        return $query
            ->where('source_type', CorpusSourceType::Page)
            ->where('source_id', $page instanceof Page ? $page->id : $page);
    }

    protected static function newFactory(): CorpusItemFactory
    {
        return CorpusItemFactory::new();
    }
}
