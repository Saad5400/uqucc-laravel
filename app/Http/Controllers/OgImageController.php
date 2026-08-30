<?php

namespace App\Http\Controllers;

use App\Services\OgImageService;
use App\Support\ScreenshotConfig;
use Illuminate\Support\Facades\Log;

class OgImageController extends Controller
{
    public function __construct(
        protected OgImageService $ogImageService
    ) {}

    /**
     * The share card for a route, as a PNG.
     *
     * This is what a crawler fetches when someone posts a link to the site.
     * Drawn on demand and cached as a file, so the first share of a page pays
     * for it and every later one is a file read.
     */
    public function generate(string $route = '/')
    {
        try {
            // Normalize the route
            $normalizedRoute = '/'.trim($route, '/');
            if ($normalizedRoute === '/') {
                $normalizedRoute = '';
            }

            $cardPath = $this->ogImageService->generateRouteScreenshot(
                $normalizedRoute,
                OgImageService::TYPE_OG
            );

            return response()->file($cardPath, [
                'Content-Type' => ScreenshotConfig::mimeType(),
                'Cache-Control' => ScreenshotConfig::cacheControl(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate OG image', [
                'route' => $route,
                'normalized_route' => $normalizedRoute ?? null,
                'request_host' => request()->getSchemeAndHttpHost(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // A preview is worth less than the page it points at: the crawler
            // is told the image is unavailable and the link still works. The
            // exception deliberately does not escape — unlike the bot's card,
            // where the image IS the reply.
            return response('Failed to generate OG image', 500);
        }
    }
}
