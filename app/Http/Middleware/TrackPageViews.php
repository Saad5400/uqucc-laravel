<?php

namespace App\Http\Middleware;

use App\Models\Page;
use App\Models\PageViewStat;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackPageViews
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only track successful GET requests
        if (
            $request->isMethod('GET')
        ) {
            $this->trackPageView($request);
        }

        return $response;
    }

    /**
     * Track the page view
     */
    protected function trackPageView(Request $request): void
    {
        try {
            // Get the current page by matching the request path
            $path = $request->path();
            $slug = $path === '/' ? '/' : '/'.$path;

            $page = Page::where('slug', $slug)->first();

            if ($page) {
                PageViewStat::track(
                    pageId: $page->id,
                    userId: auth()->id(),
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent()
                );
            }
        } catch (\Exception $e) {
            // Silently fail - don't break the request
            logger()->error('Failed to track page view', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
            ]);
        }
    }
}
