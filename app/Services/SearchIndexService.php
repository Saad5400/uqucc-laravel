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
     * Uses chunking to avoid timeout on large datasets.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildIndex(): array
    {
        $allPages = collect(); // لتخزين كل الصفحات معاً

        // معالجة في chunks (كل 50 صفحة) لتجنب Timeout
        Page::visible()
            ->select('id', 'slug', 'title', 'icon', 'parent_id', 'html_content', 'smart_search')
            ->orderBy('title')
            ->chunk(50, function ($pages) use (&$allPages) {
                $allPages = $allPages->concat($pages);
            });

        // الآن نعمل الـ map على كل الصفحات
        return $allPages
            ->map(function (Page $page) use ($allPages) {
                $breadcrumbs = $this->buildBreadcrumbTitles($page, $allPages);
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
        $maxDepth = 10; // حماية من loops لانهائية

        while ($current->parent_id && $maxDepth-- > 0) {
            /** @var Page|null $parent */
            $parent = $pages->firstWhere('id', $current->parent_id);

            if (! $parent) {
                break;
            }

            array_unshift($titles, $parent->title);
            $current = $parent;
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

        // Strip tags with limit to avoid memory issues
        $plainText = trim(strip_tags((string) $content));

        return Str::limit($plainText, 200); // قلل من 160 إلى 200
    }
}
