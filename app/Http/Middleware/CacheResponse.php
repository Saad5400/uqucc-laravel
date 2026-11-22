<?php

namespace App\Http\Middleware;

use App\Support\CacheFlusher;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full Page Caching Middleware
 *
 * Caches entire HTTP responses in Redis for maximum performance.
 * Bypasses all PHP/Laravel processing for cached requests.
 */
class CacheResponse
{
    /**
     * Routes/patterns to exclude from caching.
     */
    protected const EXCLUDED_PATHS = [
        'admin/*',
        'login',
        'logout',
        'register',
        '_og-image/*',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $ttl = null): Response
    {
        // Skip caching for authenticated users (they may see personalized content)
        if ($request->user()) {
            return $next($request);
        }

        $cacheKey = self::cacheKeyFor($request);

        if ($cacheKey === null) {
            return $next($request);
        }

        // Try to get cached response
        $cachedResponse = Cache::get($cacheKey);

        if ($cachedResponse !== null) {
            return self::responseFromCache($cachedResponse);
        }

        // Process the request
        $response = $next($request);

        // Only cache successful responses
        if ($this->shouldCacheResponse($response)) {
            $cacheTtl = $ttl ? (int) $ttl : config('app-cache.pages.response_ttl', 600);
            $this->cacheResponse($cacheKey, $response, $cacheTtl);
        }

        return $response;
    }

    /**
     * The cache key a request reads and writes, or null when it must bypass
     * the cache — the one rule this middleware and the pre-session
     * {@see ServeCachedPage} share.
     *
     * Only GETs of a URL WITHOUT a query string are cached. No cached page
     * reads a query parameter, so every `?utm_…`, `?fbclid=…` or cache-busting
     * variant would store one more full copy of the same HTML in the shared,
     * never-evicting Redis — fbclid alone is unique per click. Nor could such
     * variants share the clean URL's entry: the Inertia page object carries
     * the URL it was rendered for, and the client writes it into the address
     * bar, so another visitor's tracking parameters would replace the
     * visitor's own (and analytics would read those).
     */
    public static function cacheKeyFor(Request $request): ?string
    {
        if (! $request->isMethod('GET')
            || $request->getQueryString() !== null
            || self::isExcludedPath($request)
            || self::isPartialReload($request)) {
            return null;
        }

        return self::keyFor($request->url(), (bool) $request->header('X-Inertia'));
    }

    /**
     * The cache key for one cacheable URL.
     *
     * The stored HTML embeds the hashed asset URLs of the build it was
     * rendered against, so a deploy that ships a new build leaves every cached
     * page pointing at files that no longer exist on disk — the page renders,
     * its JS and CSS 404, and the site looks dead until the TTL runs out.
     * Folding the manifest hash into the key retires the whole family on its
     * own the moment a new build lands, so no deploy step can forget to.
     */
    public static function keyFor(string $url, bool $isInertia = false): string
    {
        $prefix = config('app-cache.keys.response_cache', 'response_cache');
        $build = Vite::manifestHash() ?? '';

        return $prefix.':'.md5($url.($isInertia ? ':inertia' : '').'|'.$build);
    }

    /**
     * An Inertia partial reload — any of X-Inertia-Partial-Data / -Except /
     * -Component — answers with a subset of the page's props, and is keyed
     * like the full Inertia visit, so it must neither read nor store: one
     * anonymous `X-Inertia-Partial-Except: page` would otherwise poison the
     * URL's Inertia entry for everyone until the TTL runs out.
     */
    protected static function isPartialReload(Request $request): bool
    {
        foreach (array_keys($request->headers->all()) as $name) {
            if (str_starts_with($name, 'x-inertia-partial-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the path should be excluded from caching.
     */
    protected static function isExcludedPath(Request $request): bool
    {
        $path = $request->path();

        // Exclude paths that exceed fnmatch's maximum length (4096 characters)
        if (strlen($path) > 4096) {
            return true;
        }

        foreach (self::EXCLUDED_PATHS as $pattern) {
            if ($pattern === $path || fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the response should be cached.
     */
    protected function shouldCacheResponse(Response $response): bool
    {
        // Only cache 200 OK responses
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        // Don't cache responses with Set-Cookie headers (sessions, etc.)
        // But allow Inertia responses which may have cookies
        $cookies = $response->headers->getCookies();
        $hasNonSessionCookies = collect($cookies)->contains(function ($cookie) {
            $name = $cookie->getName();

            // Allow session and XSRF cookies
            return ! str_contains($name, 'session') && ! str_contains($name, 'XSRF');
        });

        if ($hasNonSessionCookies) {
            return false;
        }

        return true;
    }

    /**
     * Cache the response.
     */
    protected function cacheResponse(string $cacheKey, Response $response, int $ttl): void
    {
        $data = [
            'content' => $response->getContent(),
            'status' => $response->getStatusCode(),
            'headers' => $this->getCacheableHeaders($response),
        ];

        Cache::put($cacheKey, $data, $ttl);
    }

    /**
     * Get headers that should be cached.
     */
    protected function getCacheableHeaders(Response $response): array
    {
        $headers = [];
        $cacheableHeaders = [
            'Content-Type',
            'X-Inertia',
            'X-Inertia-Version',
            'Vary',
        ];

        foreach ($cacheableHeaders as $header) {
            if ($response->headers->has($header)) {
                $headers[$header] = $response->headers->get($header);
            }
        }

        return $headers;
    }

    /**
     * Build a response from cached data.
     *
     * @param  array{content: string, status: int, headers?: array<string, string|null>}  $cached
     */
    public static function responseFromCache(array $cached): Response
    {
        $response = response($cached['content'], $cached['status']);

        // Restore cached headers
        foreach ($cached['headers'] ?? [] as $key => $value) {
            $response->headers->set($key, $value);
        }

        // The HTML page and the Inertia JSON of one URL are different bodies,
        // so a browser must never answer one from its cache for the other
        // (Back after an Inertia visit would show raw JSON). Inertia adds this
        // on the way out of its middleware — after CacheResponse stored the
        // entry, and never at all for a hit served before the web group.
        $vary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary'))));

        if (! in_array('x-inertia', array_map('strtolower', $vary), true)) {
            $response->headers->set('Vary', implode(', ', [...$vary, 'X-Inertia']));
        }

        // Add cache hit header for debugging
        $response->headers->set('X-Cache', 'HIT');
        $response->headers->set('X-Cache-Time', now()->toIso8601String());

        return $response;
    }

    /**
     * Clear all cached responses.
     */
    public static function clearAll(): void
    {
        CacheFlusher::forgetMatching(config('app-cache.keys.response_cache', 'response_cache').':*');
    }

    /**
     * Clear cached response for a specific URL.
     */
    public static function clearUrl(string $url): void
    {
        // Clear both regular and Inertia versions
        Cache::forget(self::keyFor($url));
        Cache::forget(self::keyFor($url, true));
    }

    /**
     * Clear cached response for a specific path.
     */
    public static function clearPath(string $path): void
    {
        $url = url($path);
        self::clearUrl($url);
    }
}
