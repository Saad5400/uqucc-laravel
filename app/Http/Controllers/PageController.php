<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Storage;

class PageController extends Controller
{
    /**
     * Display the homepage
     */
    public function home(): Response
    {
        $page = $this->getCachedPage('/');

        if (! $page) {
            // If no homepage exists yet, show welcome message
            return Inertia::render('Welcome');
        }

        return $this->renderPage($page);
    }

    /**
     * Display a content page by slug
     */
    public function show(string $slug): Response
    {
        // Normalize slug (ensure leading slash, no trailing slash)
        $normalizedSlug = '/'.trim($slug, '/');

        $page = $this->getCachedPageBySlug($normalizedSlug);

        if (! $page) {
            abort(404);
        }

        return $this->renderPage($page);
    }

    /**
     * Get a cached page by slug (for homepage).
     */
    private function getCachedPage(string $slug): ?Page
    {
        $cacheKey = $this->getPageCacheKey($slug);

        return Cache::remember(
            $cacheKey,
            config('app-cache.pages.ttl', 1800),
            fn () => Page::where('slug', $slug)->first()
        );
    }

    /**
     * Get a cached page by slug with visibility check and eager loading.
     */
    private function getCachedPageBySlug(string $slug): ?Page
    {
        $cacheKey = $this->getPageCacheKey($slug);

        return Cache::remember(
            $cacheKey,
            config('app-cache.pages.ttl', 1800),
            fn () => Page::where('slug', $slug)
                ->where('hidden', false)
                ->with(['users', 'children' => function ($query) {
                    $query->where('hidden', false)->orderBy('order');
                }])
                ->first()
        );
    }

    /**
     * Generate a cache key for a page.
     */
    private function getPageCacheKey(string $slug): string
    {
        $normalizedSlug = str_replace('/', '_', trim($slug, '/')) ?: 'home';
        return config('app-cache.keys.page', 'page') . ':' . $normalizedSlug;
    }

    /**
     * Render a page with all necessary data
     */
    private function renderPage(Page $page): Response
    {
        // Get breadcrumbs (cached)
        $breadcrumbs = $this->getCachedBreadcrumbs($page);

        // Eager load relationships if not already loaded
        if (! $page->relationLoaded('users')) {
            $page->load('users');
        }
        if (! $page->relationLoaded('children')) {
            $page->load(['children' => function ($query) {
                $query->where('hidden', false)->orderBy('order');
            }]);
        }

        return Inertia::render('ContentPage', [
            'page' => [
                'id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->title,
                'html_content' => $page->html_content,
                'icon' => $page->icon,
                'users' => $page->users->map(fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'url' => $user->url,
                    'avatar' => $user->avatar,
                ])->toArray(),
                'children' => $page->children->map(fn ($child) => [
                    'id' => $child->id,
                    'slug' => $child->slug,
                    'title' => $child->title,
                    'icon' => $child->icon,
                ])->toArray(),
                'catalog' => $this->getCachedCatalogPages($page),
                'quick_response' => [
                    'enabled' => $page->quick_response_enabled,
                    'send_link' => $page->quick_response_send_link,
                    'message' => $page->quick_response_message,
                    'buttons' => collect($page->quick_response_buttons ?? [])
                        ->filter(fn ($btn) => filled($btn['text'] ?? null) && filled($btn['url'] ?? null))
                        ->values()
                        ->toArray(),
                    'attachments' => collect($page->quick_response_attachments ?? [])
                        ->filter()
                        ->map(fn (string $path) => [
                            'name' => basename($path),
                            'url' => Storage::disk('public')->url($path),
                        ])
                        ->values()
                        ->toArray(),
                ],
            ],
            'breadcrumbs' => $breadcrumbs,
            'hasContent' => ! empty($page->html_content),
        ]);
    }

    /**
     * Get cached breadcrumbs for a page.
     */
    private function getCachedBreadcrumbs(Page $page): array
    {
        $version = $page->updated_at ? $page->updated_at->timestamp : '0';
        $cacheKey = config('app-cache.keys.page_breadcrumbs', 'page_breadcrumbs') . ':' . $page->id . ':' . $version;

        return Cache::remember(
            $cacheKey,
            config('app-cache.pages.breadcrumbs_ttl', 3600),
            fn () => $this->getBreadcrumbs($page)
        );
    }

    /**
     * Build breadcrumb trail for a page
     */
    private function getBreadcrumbs(Page $page): array
    {
        $breadcrumbs = collect([$page]);
        $current = $page;

        // Walk up the tree to root
        while ($current->parent_id) {
            $current = Page::find($current->parent_id);
            if ($current) {
                $breadcrumbs->prepend($current);
            }
        }

        return $breadcrumbs->map(fn ($p) => [
            'title' => $p->title,
            'path' => $p->slug,
        ])->toArray();
    }

    /**
     * Get cached catalog pages for a page.
     */
    private function getCachedCatalogPages(Page $page): array
    {
        $version = $page->updated_at ? $page->updated_at->timestamp : '0';
        $cacheKey = config('app-cache.keys.catalog_pages', 'catalog_pages') . ':' . $page->id . ':' . $version;

        return Cache::remember(
            $cacheKey,
            config('app-cache.pages.catalog_ttl', 1800),
            fn () => $this->getCatalogPages($page)
        );
    }

    /**
     * Get catalog pages for a page.
     * For the homepage, show other root-level pages; otherwise, show the page's children.
     *
     * @return array<int, array{id:int,slug:string,title:string,icon:?string}>
     */
    private function getCatalogPages(Page $page): array
    {
        $catalogQuery = $page->slug === '/'
            ? Page::whereNull('parent_id')
                ->where('hidden', false)
                ->where('slug', '!=', '/')
                ->orderBy('order')
            : $page->children()->where('hidden', false)->orderBy('order');

        return $catalogQuery->get()->map(fn (Page $child) => [
            'id' => $child->id,
            'slug' => $child->slug,
            'title' => $child->title,
            'icon' => $child->icon,
        ])->toArray();
    }
}
