<?php

namespace App\Observers;

use App\Ai\Corpus\IngestPage;
use App\Jobs\Ai\IngestPageJob;
use App\Models\Page;

/**
 * Keeps the AI corpus in sync with the CMS: every page save, delete, or
 * restore queues a re-ingestion on the "ai" queue. The job itself decides
 * what that means (index, refresh, or evict) from the page's current state,
 * and the content checksum makes redundant dispatches near-free.
 *
 * Registered from AiServiceProvider — deliberately NOT in Page::booted(), so
 * the Page model stays untouched by the AI integration. Dispatch is gated on
 * the ingest pipeline being enabled to avoid queueing dead jobs while AI
 * search is switched off.
 */
class PageCorpusObserver
{
    public function __construct(private readonly IngestPage $ingest) {}

    public function saved(Page $page): void
    {
        $this->queueSync($page);
    }

    public function deleted(Page $page): void
    {
        $this->queueSync($page);
    }

    public function restored(Page $page): void
    {
        $this->queueSync($page);
    }

    private function queueSync(Page $page): void
    {
        if (! $this->ingest->isEnabled()) {
            return;
        }

        IngestPageJob::dispatch($page->id);
    }
}
