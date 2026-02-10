<?php

namespace App\Http\Middleware;

use App\Services\SearchIndexService;
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
        $shared = [
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
        ];

        // Only share search data for GET requests to reduce memory load
        // Search data is cached but when it expires, rebuilding it is memory-intensive
        // This prevents rebuilding on POST/PUT/DELETE requests (form submissions, API calls, etc.)
        if ($request->isMethod('GET')) {
            $shared['searchData'] = fn () => app(SearchIndexService::class)->getCachedIndex();
        }

        return $shared;
    }
}
