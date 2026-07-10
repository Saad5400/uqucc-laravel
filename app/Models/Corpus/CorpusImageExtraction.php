<?php

namespace App\Models\Corpus;

use Database\Factories\Corpus\CorpusImageExtractionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One cached vision-model transcription of an image embedded in a CMS page —
 * the permanent OCR cache behind {@see \App\Ai\Corpus\PageImageExtractor}.
 *
 * `content_hash` is the cache key: the sha-256 of the image FILE bytes when
 * the image is locally resolvable, else of its URL. Statuses:
 *
 *  - extracted — the vision model transcribed it; `extracted_text` holds the
 *    markdown (possibly empty for a text-free image). Never re-OCRed.
 *  - failed    — the vision call errored; retried on a later ingest.
 *  - skipped   — an external-host image the pipeline never fetches.
 *
 * @property int $id
 * @property string $content_hash
 * @property string $source_url
 * @property string|null $extracted_text
 * @property string|null $model
 * @property string $status
 */
class CorpusImageExtraction extends Model
{
    /** @use HasFactory<CorpusImageExtractionFactory> */
    use HasFactory;

    public const STATUS_EXTRACTED = 'extracted';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'content_hash',
        'source_url',
        'extracted_text',
        'model',
        'status',
    ];

    protected static function newFactory(): CorpusImageExtractionFactory
    {
        return CorpusImageExtractionFactory::new();
    }
}
