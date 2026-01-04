<?php

namespace App\Http\Controllers;

use App\Services\OgImageService;
use App\Support\ScreenshotConfig;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class OgImageController extends Controller
{
    public function __construct(
        protected OgImageService $ogImageService
    ) {
    }

    /**
     * Generate and return an OG image for a given route.
     */
    public function generate(string $route = '/')
    {
        try {
            // Normalize the route
            $normalizedRoute = '/'.trim($route, '/');
            if ($normalizedRoute === '/') {
                $normalizedRoute = '';
            }

            // Generate the screenshot
            $screenshotPath = $this->ogImageService->generateRouteScreenshot(
                $normalizedRoute,
                OgImageService::TYPE_OG
            );

            // Return the image
            return response()->file($screenshotPath, [
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

            // Return a 500 error
            return response('Failed to generate OG image', 500);
        }
    }
}
