<?php

namespace App\Models;

use Database\Factories\PageChangeRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A page edit a review-mode editor submitted for approval. The authoring
 * editor's write is captured here as a `pending` request holding the exact
 * validated partial payload; the live page is untouched until a reviewer
 * approves it ({@see \App\Http\Controllers\Manage\PageChangeRequestController}),
 * which replays the payload through Eloquent so the `Page::booted()` cache
 * flushes still fire. Mirrors the review-first PageContentProposal contract.
 *
 * @property int $id
 * @property int $page_id
 * @property int|null $author_id
 * @property int|null $reviewed_by
 * @property array<string, mixed> $payload
 * @property string $status
 * @property string|null $review_note
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 */
class PageChangeRequest extends Model
{
    /** @use HasFactory<PageChangeRequestFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'page_id',
        'author_id',
        'reviewed_by',
        'payload',
        'status',
        'review_note',
        'reviewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * The target page, trashed included — a request against a since-trashed
     * page must still render on the review screen (approval re-validates).
     *
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    protected static function newFactory(): PageChangeRequestFactory
    {
        return PageChangeRequestFactory::new();
    }
}
