<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            // Navigation tree (cached, auto-invalidates on page changes)
            'navigation' => fn () => cache()->remember(
                config('app-cache.keys.navigation_tree'),
                config('app-cache.navigation.ttl'),
                fn () => app(\App\Services\NavigationService::class)->buildTree()
            ),
            // Search data (cached, auto-invalidates on page changes)
            'searchData' => fn () => cache()->remember(
                config('app-cache.keys.search_data'),
                config('app-cache.search.ttl'),
                fn () => \App\Models\Page::visible()
                    ->select('slug', 'title')
                    ->orderBy('title')
                    ->get()
                    ->map(fn ($page) => [
                        'id' => $page->slug,
                        'title' => $page->title,
                        'content' => '',
                        'slug' => $page->slug,
                    ])
                    ->toArray()
            ),
        ];
    }
}
