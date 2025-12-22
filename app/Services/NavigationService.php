<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Support\Collection;

class NavigationService
{
    /**
     * Build the complete navigation tree
     */
    public function buildTree(): array
    {
        $pages = Page::where('hidden', false)
            ->orderBy('order')
            ->orderBy('title')
            ->get();

        return $this->buildHierarchy($pages);
    }

    /**
     * Build hierarchical structure recursively
     */
    private function buildHierarchy(Collection $pages, ?int $parentId = null): array
    {
        return $pages
            ->where('parent_id', $parentId)
            ->map(function (Page $page) use ($pages) {
                return [
                    'id' => $page->id,
                    'title' => $page->title,
                    'path' => $page->slug,
                    'icon' => $page->icon,
                    'order' => $page->order,
                    'stem' => $page->stem,
                    'children' => $this->buildHierarchy($pages, $page->id),
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Get cached navigation tree
     */
    public function getCachedTree(): array
    {
        return cache()->remember(
            config('app-cache.keys.navigation_tree'),
            config('app-cache.navigation.ttl'),
            fn () => $this->buildTree()
        );
    }

    /**
     * Clear navigation cache
     */
    public function clearCache(): void
    {
        cache()->forget(config('app-cache.keys.navigation_tree'));
    }
}
