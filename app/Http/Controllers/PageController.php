<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    /**
     * Display the homepage
     */
    public function home(): Response
    {
        $page = Page::where('slug', '/')->first();

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

        $page = Page::where('slug', $normalizedSlug)
            ->where('hidden', false)
            ->with(['authors', 'children' => function ($query) {
                $query->where('hidden', false)->orderBy('order');
            }])
            ->firstOrFail();

        return $this->renderPage($page);
    }

    /**
     * Render a page with all necessary data
     */
    private function renderPage(Page $page): Response
    {
        // Get breadcrumbs
        $breadcrumbs = $this->getBreadcrumbs($page);

        return Inertia::render('ContentPage', [
            'page' => [
                'id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->title,
                'description' => $page->description,
                'html_content' => $page->html_content,
                'icon' => $page->icon,
                'og_image' => $page->og_image,
                'authors' => $page->authors->map(fn ($author) => [
                    'id' => $author->id,
                    'name' => $author->name,
                    'username' => $author->username,
                    'url' => $author->url,
                ])->toArray(),
                'children' => $page->children->map(fn ($child) => [
                    'id' => $child->id,
                    'slug' => $child->slug,
                    'title' => $child->title,
                    'icon' => $child->icon,
                ])->toArray(),
            ],
            'breadcrumbs' => $breadcrumbs,
            'hasContent' => ! empty($page->html_content),
        ]);
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
}
