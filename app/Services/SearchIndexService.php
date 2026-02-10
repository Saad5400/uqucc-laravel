<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SearchIndexService
{
    /**
     * Build cached search index for public website.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCachedIndex(): array
    {
        return cache()->remember(
            config('app-cache.keys.search_data'),
            config('app-cache.search.ttl'),
            fn () => $this->buildIndex()
        );
    }

    /**
     * Build a rich search index with breadcrumbs, previews, and keywords.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildIndex(): array
    {
        $pages = Page::visible()
            ->select('id', 'slug', 'title', 'icon', 'parent_id', 'html_content', 'smart_search')
            ->orderBy('title')
            ->get();
        $pagesById = $pages->keyBy('id');

        return $pages
            ->map(function (Page $page) use ($pagesById) {
                $breadcrumbs = $this->buildBreadcrumbTitles($page, $pagesById);
                $preview = $this->makeExcerpt($page->html_content);

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
    }

    /**
     * Build breadcrumb titles for a page using in-memory page collection.
     *
     * @param  Collection<int, Page>  $pages
     * @return array<int, string>
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

            /** @var Page|null $parent */
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
     *
     * @param  array<int, string>  $breadcrumbs
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
    private function makeExcerpt(mixed $content): string
    {
        if (is_array($content)) {
            $content = json_encode($content);
        }

        $plainText = trim(preg_replace('/\s+/', ' ', strip_tags((string) $content)) ?? '');

        return Str::limit($plainText, 160);
    }
}
