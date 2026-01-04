<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Support\Facades\Cache;
use Spatie\Browsershot\Browsershot;

class OgImageService
{
    public const TYPE_BOT = 'bot';

    public const TYPE_OG = 'og';

    protected array $dimensions = [
        self::TYPE_BOT => ['width' => 720, 'height' => 720],
        self::TYPE_OG => ['width' => 720, 'height' => 378],
    ];

    /**
     * Generate a screenshot for a given URL with specified type.
     */
    public function generateScreenshot(string $url, string $type = self::TYPE_OG, ?string $cacheKey = null): string
    {
        $dimensions = $this->dimensions[$type] ?? $this->dimensions[self::TYPE_OG];
        $cacheKey = $cacheKey ?? $this->buildCacheKey($url, $type);
        $screenshotPath = $this->getScreenshotPath($cacheKey, $type);

        // Check if cached screenshot exists and is valid
        if (file_exists($screenshotPath) && Cache::has($cacheKey)) {
            return $screenshotPath;
        }

        // Ensure screenshots directory exists
        $screenshotsDir = dirname($screenshotPath);
        if (! is_dir($screenshotsDir)) {
            mkdir($screenshotsDir, 0755, true);
        }

        try {
            $browsershot = Browsershot::url($url)
                ->windowSize($dimensions['width'], $dimensions['height'])
                ->deviceScaleFactor(1)
                ->waitUntilNetworkIdle()
                ->timeout(60)
                ->dismissDialogs()
                ->setScreenshotType('webp')
                ->setOption('addStyleTag', json_encode([
                    'content' => '.screenshot-hidden { display: none !important; } html { scrollbar-gutter: auto !important; }',
                ]))
                // Wait for fonts to be loaded before screenshot
                ->waitForFunction('document.fonts.ready.then(() => true)');

            // Set Chrome/Node paths from config if available (for Nixpacks deployment)
            if ($chromePath = config('services.browsershot.chrome_path')) {
                $browsershot->setChromePath($chromePath);
            }
            if ($nodeBinary = config('services.browsershot.node_binary')) {
                $browsershot->setNodeBinary($nodeBinary);
            }
            if ($nodeModulesPath = config('services.browsershot.node_modules_path')) {
                $browsershot->setNodeModulePath($nodeModulesPath);
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
                // Fix crash reporter error
                'disable-crash-reporter',
                'disable-breakpad',
                // Font rendering
                'font-render-hinting=none',
                'disable-font-subpixel-positioning',
                'enable-font-antialiasing',
            ]);

            $browsershot->save($screenshotPath);

            // Cache the screenshot path for the configured TTL
            Cache::put($cacheKey, $screenshotPath, config('app-cache.screenshots.ttl'));

            return $screenshotPath;
        } catch (\Exception $e) {
            // Clean up on error
            if (file_exists($screenshotPath)) {
                @unlink($screenshotPath);
            }
            throw $e;
        }
    }

    /**
     * Generate a screenshot for a Page model (for bot commands).
     * Cleans up old versions of the screenshot before generating a new one.
     */
    public function generatePageScreenshot(Page $page, string $type = self::TYPE_BOT): string
    {
        $url = url($page->slug);
        $cacheKey = $this->getPageCacheKey($page, $type);
        $screenshotPath = $this->getScreenshotPath($cacheKey, $type);

        // If we already have a valid cached version, return it
        if (file_exists($screenshotPath) && Cache::has($cacheKey)) {
            return $screenshotPath;
        }

        // Clean up old versions of this page's screenshots before generating new one
        // This prevents accumulation of versioned screenshot files
        $this->clearOldScreenshots($page->slug);

        return $this->generateScreenshot($url, $type, $cacheKey);
    }

    /**
     * Check if a cached screenshot exists for a Page without generating it.
     * Useful for determining if a loading message should be shown.
     */
    public function hasPageScreenshot(Page $page, string $type = self::TYPE_BOT): bool
    {
        $cacheKey = $this->getPageCacheKey($page, $type);
        $screenshotPath = $this->getScreenshotPath($cacheKey, $type);

        return file_exists($screenshotPath) && Cache::has($cacheKey);
    }

    /**
     * Generate a screenshot for a route (for OG images).
     * Uses the current request's scheme and host to ensure correct URL in production.
     */
    public function generateRouteScreenshot(string $route, string $type = self::TYPE_OG): string
    {
        // Use request's scheme and host for production compatibility
        // This ensures we use the actual public URL, not APP_URL which might be localhost
        if (app()->runningInConsole()) {
            $url = url($route);
        } else {
            $request = request();
            $baseUrl = $request->getSchemeAndHttpHost();
            $url = rtrim($baseUrl, '/').'/'.ltrim($route, '/');

            // Handle root route case
            if ($route === '' || $route === '/') {
                $url = rtrim($baseUrl, '/');
            }
        }

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
