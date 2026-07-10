<?php

namespace App\Jobs\Ai;

use App\Ai\Corpus\IngestPage;
use App\Models\Page;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued (re-)ingestion of one page into the AI corpus, dispatched by
 * PageCorpusObserver on every save/delete/restore and by ai:ingest-pages.
 *
 * Runs on the dedicated "ai" queue so embedding calls never compete with the
 * Telegram queue or user-facing work. Carries only the page id: the page is
 * re-read at run time, so the job acts on current state — a page deleted or
 * hidden between dispatch and execution is evicted, not indexed.
 */
class IngestPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $pageId)
    {
        $this->onQueue('ai');
    }

    public function handle(IngestPage $ingest): void
    {
        $page = Page::withTrashed()->find($this->pageId);

        if ($page === null) {
            $ingest->forget($this->pageId);

            return;
        }

        $ingest->ingest($page);
    }
}
