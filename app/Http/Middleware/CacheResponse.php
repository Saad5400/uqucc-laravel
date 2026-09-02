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
    protected array $excludedPaths = [
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
        // Only cache GET requests
        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        // Skip caching for authenticated users (they may see personalized content)
        if ($request->user()) {
            return $next($request);
        }

        // Skip caching for excluded paths
        if ($this->isExcludedPath($request)) {
            return $next($request);
        }

        // Skip caching for Inertia partial reloads (they need fresh data)
        if ($request->header('X-Inertia-Partial-Data')) {
            return $next($request);
        }

        // Skip if request has query parameters that might affect output
        // Allow caching with query params but include them in the cache key
        $cacheKey = $this->getCacheKey($request);

        // Try to get cached response
        $cachedResponse = Cache::get($cacheKey);

        if ($cachedResponse !== null) {
            return $this->buildResponseFromCache($cachedResponse, $request);
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
     * Generate a unique cache key for the request.
     */
    protected function getCacheKey(Request $request): string
    {
        return self::keyFor($request->fullUrl(), (bool) $request->header('X-Inertia'));
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
     * Check if the path should be excluded from caching.
     */
    protected function isExcludedPath(Request $request): bool
    {
        $path = $request->path();

        // Exclude paths that exceed fnmatch's maximum length (4096 characters)
        if (strlen($path) > 4096) {
            return true;
        }

        foreach ($this->excludedPaths as $pattern) {
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
     */
    protected function buildResponseFromCache(array $cached, Request $request): Response
    {
        $response = response($cached['content'], $cached['status']);

        // Restore cached headers
        foreach ($cached['headers'] ?? [] as $key => $value) {
            $response->headers->set($key, $value);
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
