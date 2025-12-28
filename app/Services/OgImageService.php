<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

class OgImageService
{
    public const TYPE_BOT = 'bot';

    public const TYPE_OG = 'og';

    protected array $dimensions = [
        self::TYPE_BOT => ['width' => 720, 'height' => 720],
        self::TYPE_OG => ['width' => 1200, 'height' => 630],
    ];

    /**
     * Generate a screenshot for a given URL with specified type.
     */
    public function generateScreenshot(string $url, string $type = self::TYPE_OG, ?string $cacheKey = null): string
    {
        $debug = config('app.debug', false);
        $startTime = microtime(true);

        $dimensions = $this->dimensions[$type] ?? $this->dimensions[self::TYPE_OG];
        $cacheKey = $cacheKey ?? $this->buildCacheKey($url, $type);
        $screenshotPath = $this->getScreenshotPath($cacheKey, $type);

        // Check if cached screenshot exists and is valid
        if (file_exists($screenshotPath) && Cache::has($cacheKey)) {
            if ($debug) {
                Log::info('Using cached screenshot', [
                    'url' => $url,
                    'type' => $type,
                    'cache_key' => $cacheKey,
                    'path' => $screenshotPath,
                    'file_size' => filesize($screenshotPath),
                ]);
            }

            return $screenshotPath;
        }

        // Ensure screenshots directory exists
        $screenshotsDir = dirname($screenshotPath);
        if (! is_dir($screenshotsDir)) {
            if ($debug) {
                Log::info('Creating screenshots directory', [
                    'directory' => $screenshotsDir,
                ]);
            }

            mkdir($screenshotsDir, 0755, true);
        }

        // Verify directory is writable
        if (! is_writable($screenshotsDir)) {
            Log::error('Screenshots directory is not writable', [
                'directory' => $screenshotsDir,
                'permissions' => substr(sprintf('%o', fileperms($screenshotsDir)), -4),
            ]);
            throw new \RuntimeException("Screenshots directory is not writable: {$screenshotsDir}");
        }

        try {
            if ($debug) {
                Log::info('Starting screenshot generation', [
                    'url' => $url,
                    'type' => $type,
                    'dimensions' => $dimensions,
                    'cache_key' => $cacheKey,
                    'target_path' => $screenshotPath,
                ]);
            }

            $browsershot = Browsershot::url($url)
                ->windowSize($dimensions['width'], $dimensions['height'])
                ->deviceScaleFactor(1)
                ->waitUntilNetworkIdle()
                ->delay(500) // Wait 500ms after network idle to ensure DOM is fully rendered
                ->timeout(60)
                ->dismissDialogs()
                ->setScreenshotType('webp')
                ->setOption('addStyleTag', json_encode([
                    'content' => '.screenshot-hidden { display: none !important; } html { scrollbar-gutter: auto !important; }',
                ]));

            // Set Chrome/Node paths from config if available (for Nixpacks deployment)
            $chromePath = config('services.browsershot.chrome_path');
            $nodeBinary = config('services.browsershot.node_binary');
            $nodeModulesPath = config('services.browsershot.node_modules_path');

            if ($chromePath) {
                $browsershot->setChromePath($chromePath);
                if ($debug) {
                    Log::info('Using custom Chrome path', ['path' => $chromePath]);
                }
            }

            if ($nodeBinary) {
                $browsershot->setNodeBinary($nodeBinary);
                if ($debug) {
                    Log::info('Using custom Node binary', ['path' => $nodeBinary]);
                }
            }

            if ($nodeModulesPath) {
                $browsershot->setNodeModulePath($nodeModulesPath);
                if ($debug) {
                    Log::info('Using custom Node modules path', ['path' => $nodeModulesPath]);
                }
            }

            $browsershot->addChromiumArguments([
                'no-sandbox',
                'disable-setuid-sandbox',
                'disable-dev-shm-usage',
                'disable-gpu',
                'disable-web-security',
                'disable-extensions',
                'disable-plugins',
                'disable-default-apps',
                'disable-background-timer-throttling',
                'disable-backgrounding-occluded-windows',
                'disable-renderer-backgrounding',
                'disable-features=TranslateUI',
                'disable-component-update',
                'disable-domain-reliability',
                'disable-sync',
                'disable-client-side-phishing-detection',
                'disable-permissions-api',
                'disable-notifications',
                'disable-desktop-notifications',
                'disable-background-networking',
                'memory-pressure-off',
                'max_old_space_size=128',
                'aggressive-cache-discard',
            ]);

            if ($debug) {
                Log::info('Executing Browsershot', [
                    'url' => $url,
                    'chrome_path' => $chromePath ?: 'system default',
                    'node_binary' => $nodeBinary ?: 'system default',
                ]);
            }

            $browsershot->save($screenshotPath);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if (! file_exists($screenshotPath)) {
                Log::error('Screenshot file was not created', [
                    'url' => $url,
                    'expected_path' => $screenshotPath,
                    'duration_ms' => $duration,
                ]);
                throw new \RuntimeException('Screenshot file was not created');
            }

            $fileSize = filesize($screenshotPath);
            if ($fileSize === 0) {
                Log::error('Screenshot file is empty', [
                    'url' => $url,
                    'path' => $screenshotPath,
                    'duration_ms' => $duration,
                ]);
                throw new \RuntimeException('Screenshot file is empty');
            }

            // Cache the screenshot path for the configured TTL
            Cache::put($cacheKey, $screenshotPath, config('app-cache.screenshots.ttl'));

            if ($debug) {
                Log::info('Screenshot generated successfully', [
                    'url' => $url,
                    'path' => $screenshotPath,
                    'file_size' => $fileSize,
                    'duration_ms' => $duration,
                ]);
            }

            return $screenshotPath;
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('Screenshot generation failed', [
                'url' => $url,
                'type' => $type,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'duration_ms' => $duration,
                'screenshot_path' => $screenshotPath,
                'chrome_path' => config('services.browsershot.chrome_path', 'system default'),
                'node_binary' => config('services.browsershot.node_binary', 'system default'),
                'trace' => $debug ? $e->getTraceAsString() : 'Enable APP_DEBUG for stack trace',
            ]);

            // Clean up on error
            if (file_exists($screenshotPath)) {
                @unlink($screenshotPath);
            }

            throw $e;
        }
    }

    /**
     * Generate a screenshot for a Page model (for bot commands).
     */
    public function generatePageScreenshot(Page $page, string $type = self::TYPE_BOT): string
    {
        $url = url($page->slug);
        $cacheKey = $this->getPageCacheKey($page, $type);

        return $this->generateScreenshot($url, $type, $cacheKey);
    }

    /**
     * Generate a screenshot for a route (for OG images).
     */
    public function generateRouteScreenshot(string $route, string $type = self::TYPE_OG): string
    {
        $url = url($route);
        $cacheKey = $this->buildCacheKey($url, $type);

        return $this->generateScreenshot($url, $type, $cacheKey);
    }

    /**
     * Get the cache key for a Page model.
     */
    public function getPageCacheKey(Page $page, string $type = self::TYPE_BOT): string
    {
        // Use page slug and updated_at timestamp to create versioned cache key
        $version = $page->updated_at ? $page->updated_at->timestamp : '0';
        $slug = str_replace('/', '_', trim($page->slug, '/')) ?: 'home';

        return config('app-cache.keys.screenshot').":{$type}:{$slug}:{$version}";
    }

    /**
     * Build a cache key from a URL and type.
     */
    protected function buildCacheKey(string $url, string $type): string
    {
        $urlHash = md5($url);

        return config('app-cache.keys.screenshot').":{$type}:{$urlHash}";
    }

    /**
     * Get the file path for a screenshot.
     */
    protected function getScreenshotPath(string $cacheKey, string $type): string
    {
        // Extract identifier from cache key (last part after the colons)
        $parts = explode(':', $cacheKey);
        $identifier = end($parts);

        $filename = "{$type}_{$identifier}.webp";

        return storage_path("app/public/screenshots/{$filename}");
    }

    /**
     * Clear cached screenshot for a Page.
     */
    public function clearPageCache(Page $page): void
    {
        foreach ([self::TYPE_BOT, self::TYPE_OG] as $type) {
            $cacheKey = $this->getPageCacheKey($page, $type);
            $screenshotPath = $this->getScreenshotPath($cacheKey, $type);

            // Remove from cache
            Cache::forget($cacheKey);

            // Delete file if exists
            if (file_exists($screenshotPath)) {
                @unlink($screenshotPath);
            }
        }
    }

    /**
     * Clear all old screenshot files for a Page slug.
     */
    public function clearOldScreenshots(string $slug): void
    {
        $normalizedSlug = str_replace('/', '_', trim($slug, '/')) ?: 'home';
        $screenshotsDir = storage_path('app/public/screenshots');

        if (! is_dir($screenshotsDir)) {
            return;
        }

        // Find and delete all screenshots matching this slug pattern
        $pattern = "{$screenshotsDir}/*_{$normalizedSlug}_*.webp";
        $files = glob($pattern);

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
