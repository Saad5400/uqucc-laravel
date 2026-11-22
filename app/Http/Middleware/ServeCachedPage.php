<?php

namespace App\Http\Middleware;

use App\Services\PageViewRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a full-page cache hit BEFORE the web middleware group runs.
 *
 * {@see CacheResponse} is route middleware, so even a cache hit used to go
 * through the whole web group first: session start (a session written to the
 * store for every first-time visitor and crawler), cookie encryption, CSRF,
 * Inertia's shared props. This global middleware answers a visitor who has no
 * session yet straight from the same cache entries, under the same rules
 * ({@see CacheResponse::cacheKeyFor()}); it never stores anything itself.
 *
 * Anyone carrying a session or remember-me cookie may be signed in, so they
 * take the normal path, where CacheResponse decides. An Inertia visit from a
 * client on an older build also does: Inertia's middleware must answer it with
 * a 409 so the client reloads.
 *
 * The page view of a hit is still counted (after the response is sent).
 */
class ServeCachedPage
{
    public function __construct(private readonly PageViewRecorder $pageViews) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->mayBeSignedIn($request) || ! $this->isCurrentInertiaVersion($request)) {
            return $next($request);
        }

        $cacheKey = CacheResponse::cacheKeyFor($request);
        $cached = $cacheKey === null ? null : Cache::get($cacheKey);

        if ($cached === null) {
            return $next($request);
        }

        $this->pageViews->record($request, null);

        return CacheResponse::responseFromCache($cached);
    }

    private function mayBeSignedIn(Request $request): bool
    {
        if ($request->cookies->has(config('session.cookie'))) {
            return true;
        }

        foreach ($request->cookies->keys() as $name) {
            if (Str::startsWith((string) $name, 'remember_')) {
                return true;
            }
        }

        return false;
    }

    private function isCurrentInertiaVersion(Request $request): bool
    {
        if (! $request->header('X-Inertia')) {
            return true;
        }

        return $request->header('X-Inertia-Version', '') === (string) app(HandleInertiaRequests::class)->version($request);
    }
}
