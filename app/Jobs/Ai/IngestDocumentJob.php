<?php

namespace App\Jobs\Ai;

use App\Ai\Corpus\IngestDocument;
use App\Models\Corpus\CorpusDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued (re-)ingestion of one already-extracted corpus document, dispatched
 * by the manage panel's "re-ingest" action (e.g. after enabling AI search or
 * editing the extracted markdown) — chunk + embed without re-running the
 * extraction step.
 *
 * Runs on the dedicated "ai" queue. Carries only the document id: the row is
 * re-read at run time, so a document deleted between dispatch and execution
 * is evicted, not indexed.
 */
class IngestDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue('ai');
    }

    public function handle(IngestDocument $ingest): void
    {
        $document = CorpusDocument::query()->find($this->documentId);

        if ($document === null) {
            $ingest->forget($this->documentId);

            return;
        }

        $ingest->ingest($document);
    }
}
