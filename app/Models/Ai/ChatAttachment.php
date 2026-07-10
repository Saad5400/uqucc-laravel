<?php

namespace App\Models\Ai;

use Database\Factories\Ai\ChatAttachmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A file a visitor attached to the AI chat (transcript screenshot, regulation
 * PDF, …). Session-owned and PRIVATE to the chat: the extracted text is
 * injected into that visitor's conversation as context — it is never ingested
 * into the public retrieval corpus.
 *
 * The row tracks the stored file (disk + path), the text extracted from it
 * (extracted_markdown — via the PDF text layer or the vision model), and the
 * extraction lifecycle, mirroring {@see \App\Models\Corpus\CorpusDocument}.
 * ULID primary keys because ids are exposed to the anonymous client.
 *
 * @property string $id
 * @property string $session_id
 * @property string|null $conversation_id
 * @property string $original_filename
 * @property string $disk
 * @property string $path
 * @property string|null $mime
 * @property int|null $size
 * @property string $status
 * @property string|null $extracted_markdown
 * @property string|null $error
 */
class ChatAttachment extends Model
{
    /** @use HasFactory<ChatAttachmentFactory> */
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** The disk uploads are stored on. */
    public const DISK = 'local';

    /** The directory (on DISK) holding uploaded chat attachments. */
    public const DIRECTORY = 'chat-attachments';

    protected $table = 'ai_chat_attachments';

    protected $fillable = [
        'session_id',
        'conversation_id',
        'original_filename',
        'disk',
        'path',
        'mime',
        'size',
        'status',
        'extracted_markdown',
        'error',
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
     * Deleting an attachment (including via the pruning command) removes its
     * stored file so nothing orphaned remains on disk.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $attachment): void {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });
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

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    protected static function newFactory(): ChatAttachmentFactory
    {
        return ChatAttachmentFactory::new();
    }
}
