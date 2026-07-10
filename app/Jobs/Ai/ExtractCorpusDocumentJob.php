<?php

namespace App\Jobs\Ai;

use App\Ai\Corpus\IngestDocument;
use App\Ai\Corpus\UploadedTextExtractor;
use App\Models\Corpus\CorpusDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queued extraction of one uploaded corpus document, dispatched on upload and
 * by the Filament "re-extract" action: extract markdown (text layer or
 * vision) → store it on the row → chunk + embed via {@see IngestDocument}.
 *
 * Runs on the dedicated "ai" queue alongside IngestPageJob. NOT auto-retried
 * (tries = 1): a vision extraction is expensive and its failure usually needs
 * a human look (bad scan, disabled AI, missing key), so failure lands on the
 * row as status "failed" + the error message, and the admin retries
 * explicitly from Filament. The exception is reported, not rethrown — the row
 * is the source of truth for extraction state.
 */
class ExtractCorpusDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue('ai');
    }

    public function handle(UploadedTextExtractor $extractor, IngestDocument $ingest): void
    {
        $document = CorpusDocument::query()->find($this->documentId);

        if ($document === null) {
            return;
        }

        $document->update([
            'status' => CorpusDocument::STATUS_EXTRACTING,
            'error' => null,
        ]);

        try {
            $markdown = $extractor->extract($document);

            $document->update([
                'extracted_markdown' => $markdown,
                'status' => CorpusDocument::STATUS_READY,
            ]);

            $ingest->ingest($document->refresh());
        } catch (Throwable $exception) {
            $document->update([
                'status' => CorpusDocument::STATUS_FAILED,
                'error' => Str::limit($exception->getMessage(), 1000),
            ]);

            report($exception);
        }
    }
}
