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
    public function generate(string $route = '/'): Response
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
                'Content-Type' => 'image/webp',
                'Cache-Control' => 'public, max-age=604800', // Cache for 7 days
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate OG image', [
                'route' => $route,
                'error' => $e->getMessage(),
            ]);

            // Return a 500 error
            return response('Failed to generate OG image', 500);
        }
    }
}
