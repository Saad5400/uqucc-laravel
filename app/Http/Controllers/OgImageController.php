<?php

namespace App\Http\Controllers;

use App\Services\OgImageService;
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

            // Verify the file exists and is readable
            if (! file_exists($screenshotPath) || ! is_readable($screenshotPath)) {
                throw new \RuntimeException("Screenshot file not found or not readable: {$screenshotPath}");
            }

            // Return the image
            return response()->file($screenshotPath, [
                'Content-Type' => 'image/webp',
                'Cache-Control' => 'public, max-age=604800', // Cache for 7 days
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate OG image', [
                'route' => $route,
                'normalized_route' => $normalizedRoute ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Try to return a fallback image if it exists
            $fallbackPath = public_path('images/og-fallback.png');
            if (file_exists($fallbackPath)) {
                return response()->file($fallbackPath, [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'public, max-age=3600',
                ]);
            }

            // Return a more detailed error in development, generic in production
            $errorMessage = app()->environment('local')
                ? "Failed to generate OG image: {$e->getMessage()}"
                : 'Failed to generate OG image';

            return response($errorMessage, 500)
                ->header('Content-Type', 'text/plain');
        }
    }
}
