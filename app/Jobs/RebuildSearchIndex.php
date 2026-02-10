<?php

namespace App\Jobs;

use App\Models\Page;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RebuildSearchIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Use chunking to process pages in batches and avoid memory issues
        $allPages = collect();
        $chunkSize = 50;

        Page::visible()
            ->select('id', 'slug', 'title', 'icon', 'parent_id', 'smart_search')
            ->chunk($chunkSize, function (Collection $pages) use (&$allPages) {
                $allPages = $allPages->concat($pages);
            });

        $pagesById = $allPages->keyBy('id');

        $index = $allPages
            ->map(function (Page $page) use ($pagesById) {
                $breadcrumbs = $this->buildBreadcrumbTitles($page, $pagesById);
                $preview = $this->makeExcerpt($page->html_content ?? '');

                return [
                    'id' => $page->slug,
                    'title' => $page->title,
                    'slug' => $page->slug,
                    'icon' => $page->icon,
                    'breadcrumb' => implode(' / ', $breadcrumbs),
                    'preview' => $preview,
                    'keywords' => $this->buildKeywords($page, $breadcrumbs, $preview),
                    'smart' => (bool) $page->smart_search,
                ];
            })
            ->toArray();

        // Cache the index for 24 hours instead of 1 hour
        Cache::put(
            config('app-cache.keys.search_data'),
            $index,
            now()->addHours(24)
        );
    }

    /**
     * Build breadcrumb titles for a page using in-memory page collection.
     */
    private function buildBreadcrumbTitles(Page $page, Collection $pages): array
    {
        $titles = [$page->title];
        $current = $page;
        $visited = [$page->id => true];
        $maxDepth = 30;
        $depth = 0;

        while ($current->parent_id && $depth < $maxDepth) {
            if (isset($visited[$current->parent_id])) {
                break;
            }

            $parent = $pages->get($current->parent_id);

            if (! $parent) {
                break;
            }

            $visited[$parent->id] = true;
            array_unshift($titles, $parent->title);
            $current = $parent;
            $depth++;
        }

        return $titles;
    }

    /**
     * Build a lightweight keyword string to improve fuzzy matching.
     */
    private function buildKeywords(Page $page, array $breadcrumbs, string $preview): string
    {
        return collect([
            $page->title,
            $page->slug,
            implode(' ', $breadcrumbs),
            $preview,
        ])
            ->filter()
            ->map(fn (string $value) => Str::lower($value))
            ->unique()
            ->implode(' ');
    }

    /**
     * Generate a short, plain-text preview of page content.
     */
    private function makeExcerpt(string $content): string
    {
        $plainText = trim(preg_replace('/\s+/', ' ', strip_tags($content)) ?? '');

        return Str::limit($plainText, 160);
    }
}
