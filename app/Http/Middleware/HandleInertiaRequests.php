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
            // Navigation tree (cached for 1 hour)
            'navigation' => fn () => cache()->remember('navigation_tree', 3600, function () {
                return app(\App\Services\NavigationService::class)->buildTree();
            }),
            // Search data (cached for 1 hour)
            'searchData' => fn () => cache()->remember('search_data', 3600, function () {
                return \App\Models\PageSearchCache::with('page')
                    ->get()
                    ->map(fn ($item) => [
                        'id' => $item->section_id,
                        'title' => $item->title,
                        'content' => $item->content,
                        'level' => $item->level,
                    ])
                    ->toArray();
            }),
        ];
    }
}
