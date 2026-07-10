<?php

namespace App\Jobs\Ai;

use App\Ai\Authoring\PageAuthor;
use App\Models\Corpus\CorpusDocument;
use App\Models\Page;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queued document → page authoring, dispatched by the manage panel's
 * «توليد صفحة من المستند» action: {@see PageAuthor} decides new-vs-update and
 * produces an unpublished draft page or a pending content proposal.
 *
 * Runs on the dedicated "ai" queue alongside the extraction jobs. NOT
 * auto-retried (tries = 1): an authoring run is expensive (two reasoning-tier
 * generations) and its failure usually needs a human look (disabled AI,
 * exhausted budget, unusable extraction), so failure lands on the row as
 * authoring_status "failed" + the error message and the admin retries
 * explicitly from the panel. The exception is reported, not rethrown — the
 * row is the source of truth for authoring state.
 */
class AuthorPageFromDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue('ai');
    }

    public function handle(PageAuthor $author): void
    {
        $document = CorpusDocument::query()->find($this->documentId);

        if ($document === null) {
            return;
        }

        $document->update([
            'authoring_status' => CorpusDocument::AUTHORING_RUNNING,
            'authoring_error' => null,
        ]);

        try {
            $result = $author->author($document);

            $document->update([
                'authoring_status' => CorpusDocument::AUTHORING_DONE,
                'authored_page_id' => $result instanceof Page ? $result->id : $document->authored_page_id,
            ]);
        } catch (Throwable $exception) {
            $document->update([
                'authoring_status' => CorpusDocument::AUTHORING_FAILED,
                'authoring_error' => Str::limit($exception->getMessage(), 1000),
            ]);

            report($exception);
        }
    }
}
