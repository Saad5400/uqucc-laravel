<?php

namespace App\Console\Commands;

use App\Ai\Corpus\CorpusSourceType;
use App\Ai\Corpus\IngestPage;
use App\Jobs\Ai\IngestPageJob;
use App\Models\Corpus\CorpusItem;
use App\Models\Page;
use Illuminate\Console\Command;

/**
 * (Re)ingest every published page into the AI corpus, and prune corpus items
 * whose pages no longer exist or are hidden. The per-page checksum makes a
 * full re-run cheap: unchanged pages are skipped without re-embedding.
 */
class IngestPages extends Command
{
    protected $signature = 'ai:ingest-pages
        {--queue : Dispatch queued jobs on the "ai" queue instead of ingesting inline}';

    protected $description = 'Ingest all published pages into the AI search corpus';

    public function handle(IngestPage $ingest): int
    {
        // A full inline run touches every page, image download, and vision
        // response in one process — the default 128M CLI limit was exhausted
        // in production partway through the site.
        ini_set('memory_limit', '512M');

        if (! $ingest->isEnabled()) {
            $this->warn('AI search ingestion is disabled: enable ai_enabled + search_enabled in AI settings and configure an embedding driver.');

            return self::FAILURE;
        }

        $pages = Page::visible()->get();

        $pruned = $this->pruneStaleItems($pages->pluck('id'));

        foreach ($pages as $page) {
            if ($this->option('queue')) {
                IngestPageJob::dispatch($page->id);
            } else {
                $ingest->ingest($page);
            }

            $this->line(($this->option('queue') ? 'Queued: ' : 'Ingested: ').$page->slug);
        }

        $this->info(sprintf(
            '%s %d page(s), pruned %d stale corpus item(s).',
            $this->option('queue') ? 'Queued' : 'Ingested',
            $pages->count(),
            $pruned,
        ));

        return self::SUCCESS;
    }

    /**
     * Remove page-sourced corpus items that no longer correspond to a
     * published page (deleted or hidden since the last run).
     *
     * @param  \Illuminate\Support\Collection<int, int>  $publishedPageIds
     */
    private function pruneStaleItems($publishedPageIds): int
    {
        return CorpusItem::query()
            ->where('source_type', CorpusSourceType::Page)
            ->whereNotIn('source_id', $publishedPageIds)
            ->delete();
    }
}
