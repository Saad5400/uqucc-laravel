<?php

namespace App\Models\Corpus;

use App\Ai\Corpus\CorpusSourceType;
use App\Models\Ai\PageContentProposal;
use App\Models\Page;
use App\Models\User;
use App\Support\Disk;
use Database\Factories\Corpus\CorpusDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/**
 * An admin-uploaded knowledge file (regulation, guide, form) feeding the AI
 * retrieval corpus.
 *
 * The row tracks the stored file (disk + path), the text extracted from it
 * (extracted_markdown — via the PDF text layer or the vision model), and the
 * extraction lifecycle. The retrievable representation is a CorpusItem with
 * source_type "document" and source_id = this id; deleting a document also
 * deletes its stored file and evicts that item (chunks cascade in the DB).
 *
 * @property int $id
 * @property string $title
 * @property string $original_filename
 * @property string $disk
 * @property string $path
 * @property string|null $mime
 * @property int|null $size
 * @property string $status
 * @property string|null $extracted_markdown
 * @property string|null $error
 * @property string|null $authoring_status
 * @property string|null $authoring_error
 * @property int|null $authored_page_id
 * @property int|null $uploaded_by
 */
class CorpusDocument extends Model
{
    /** @use HasFactory<CorpusDocumentFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const AUTHORING_QUEUED = 'queued';

    public const AUTHORING_RUNNING = 'running';

    public const AUTHORING_DONE = 'done';

    public const AUTHORING_FAILED = 'failed';

    /** The disk uploads are stored on (env-resolved: local in dev, S3 in prod). */
    public const DISK = Disk::UPLOADS;

    /** The directory (on DISK) holding uploaded corpus files. */
    public const DIRECTORY = 'corpus-documents';

    protected $fillable = [
        'title',
        'original_filename',
        'disk',
        'path',
        'mime',
        'size',
        'status',
        'extracted_markdown',
        'error',
        'authoring_status',
        'authoring_error',
        'authored_page_id',
        'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * Deleting a document removes its stored file and evicts its corpus item
     * (chunks cascade at the database level), so nothing orphaned remains
     * retrievable or on disk.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $document): void {
            Storage::disk($document->disk)->delete($document->path);

            CorpusItem::query()
                ->where('source_type', CorpusSourceType::Document)
                ->where('source_id', $document->id)
                ->delete();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasOne<CorpusItem, $this> */
    public function corpusItem(): HasOne
    {
        return $this->hasOne(CorpusItem::class, 'source_id')
            ->where('source_type', CorpusSourceType::Document);
    }

    /**
     * The unpublished draft page the authoring AI created from this document
     * (NEW-content outcome), trashed included so the panel can still link a
     * since-trashed draft.
     *
     * @return BelongsTo<Page, $this>
     */
    public function authoredPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'authored_page_id')->withTrashed();
    }

    /**
     * Content proposals the authoring AI produced from this document
     * (UPDATE-an-existing-page outcome), newest first.
     *
     * @return HasMany<PageContentProposal, $this>
     */
    public function contentProposals(): HasMany
    {
        return $this->hasMany(PageContentProposal::class)->latest('id');
    }

    /**
     * Whether the authoring pipeline may be (re)triggered for this document.
     */
    public function canAuthor(): bool
    {
        return $this->status === self::STATUS_READY
            && filled($this->extracted_markdown)
            && ! in_array($this->authoring_status, [self::AUTHORING_QUEUED, self::AUTHORING_RUNNING], true);
    }

    /**
     * Absolute filesystem path of the stored file.
     */
    public function absolutePath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /**
     * Whether the stored file is plain text (txt / markdown) whose contents
     * are the document text itself — extraction needs no AI.
     */
    public function isText(): bool
    {
        return str_starts_with((string) $this->mime, 'text/');
    }

    /**
     * Coarse file kind for UI labelling.
     *
     * @return 'pdf'|'image'|'text'|'other'
     */
    public function fileKind(): string
    {
        return match (true) {
            $this->isPdf() => 'pdf',
            $this->isImage() => 'image',
            $this->isText() => 'text',
            default => 'other',
        };
    }

    protected static function newFactory(): CorpusDocumentFactory
    {
        return CorpusDocumentFactory::new();
    }
}
