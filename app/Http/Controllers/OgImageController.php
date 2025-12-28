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
        $startTime = microtime(true);
        $debug = config('app.debug', false);

        try {
            // Normalize the route
            $normalizedRoute = '/'.trim($route, '/');
            if ($normalizedRoute === '/') {
                $normalizedRoute = '';
            }

            $targetUrl = url($normalizedRoute);

            if ($debug) {
                Log::info('OG Image generation started', [
                    'route' => $route,
                    'normalized_route' => $normalizedRoute,
                    'target_url' => $targetUrl,
                    'timestamp' => now()->toDateTimeString(),
                ]);
            }

            // Generate the screenshot
            $screenshotPath = $this->ogImageService->generateRouteScreenshot(
                $normalizedRoute,
                OgImageService::TYPE_OG
            );

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($debug) {
                Log::info('OG Image generated successfully', [
                    'route' => $route,
                    'screenshot_path' => $screenshotPath,
                    'file_exists' => file_exists($screenshotPath),
                    'file_size' => file_exists($screenshotPath) ? filesize($screenshotPath) : 0,
                    'duration_ms' => $duration,
                ]);
            }

            // Return the image
            return response()->file($screenshotPath, [
                'Content-Type' => 'image/webp',
                'Cache-Control' => 'public, max-age=604800', // Cache for 7 days
            ]);
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Detailed error logging
            Log::error('Failed to generate OG image', [
                'route' => $route,
                'normalized_route' => $normalizedRoute ?? null,
                'target_url' => $targetUrl ?? null,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $debug ? $e->getTraceAsString() : 'Enable APP_DEBUG for stack trace',
                'duration_ms' => $duration,
                'chrome_path' => config('services.browsershot.chrome_path', 'default'),
                'node_binary' => config('services.browsershot.node_binary', 'default'),
                'php_version' => PHP_VERSION,
                'memory_usage' => memory_get_usage(true),
            ]);

            // Return detailed error in debug mode, generic error in production
            if ($debug) {
                $errorDetails = [
                    'error' => 'Failed to generate OG image',
                    'route' => $route,
                    'target_url' => $targetUrl ?? 'N/A',
                    'message' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'duration_ms' => $duration,
                    'troubleshooting' => [
                        'check_chrome' => 'Ensure Chrome/Chromium is installed',
                        'check_node' => 'Ensure Node.js is installed',
                        'check_permissions' => 'Verify storage/app/public/screenshots is writable',
                        'check_url' => 'Verify the target URL is accessible',
                        'check_logs' => 'Review storage/logs/laravel.log for details',
                    ],
                ];

                return response()->json($errorDetails, 500);
            }

            return response('Failed to generate OG image. Please check the logs for details.', 500);
        }
    }
}
