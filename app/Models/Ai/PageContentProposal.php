<?php

namespace App\Models\Ai;

use App\Models\Corpus\CorpusDocument;
use App\Models\Page;
use Database\Factories\Ai\PageContentProposalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A full-page content revision the page-authoring AI PROPOSED for an existing
 * page from an uploaded corpus document — the review-first contract of the
 * authoring feature: {@see \App\Ai\Authoring\PageAuthor} persists the row as
 * `pending` without touching the live page; a human applies it from the
 * review screen (→ `applied`, or `failed` with the error surfaced) or rejects
 * it (→ `rejected`). Mirrors the AdminPendingAction confirm flow.
 *
 * @property int $id
 * @property int $page_id
 * @property int $corpus_document_id
 * @property string $proposed_markdown
 * @property array<string, mixed>|null $proposed_html_content
 * @property string $summary
 * @property string $status
 * @property string|null $error
 * @property \Illuminate\Support\Carbon|null $applied_at
 */
class PageContentProposal extends Model
{
    /** @use HasFactory<PageContentProposalFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'page_id',
        'corpus_document_id',
        'proposed_markdown',
        'proposed_html_content',
        'summary',
        'status',
        'error',
        'applied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proposed_html_content' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * The target page, trashed included — a proposal against a since-trashed
     * page must still render on the review screen (apply re-validates).
     *
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class)->withTrashed();
    }

    /** @return BelongsTo<CorpusDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(CorpusDocument::class, 'corpus_document_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    protected static function newFactory(): PageContentProposalFactory
    {
        return PageContentProposalFactory::new();
    }
}
