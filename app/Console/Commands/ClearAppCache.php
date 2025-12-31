<?php

namespace App\Console\Commands;

use App\Http\Middleware\CacheResponse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearAppCache extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'app:clear-cache
                            {--response : Only clear response cache}
                            {--pages : Only clear page-related caches}
                            {--all : Clear all application caches}';

    /**
     * The console command description.
     */
    protected $description = 'Clear application-specific caches (response cache, page caches, etc.)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $clearAll = $this->option('all') || (! $this->option('response') && ! $this->option('pages'));

        if ($this->option('response') || $clearAll) {
            $this->clearResponseCache();
        }

        if ($this->option('pages') || $clearAll) {
            $this->clearPageCaches();
        }

        if ($clearAll) {
            $this->clearNavigationCaches();
        }

        $this->newLine();
        $this->info('✅ Cache cleared successfully!');

        return Command::SUCCESS;
    }

    /**
     * Clear response cache.
     */
    protected function clearResponseCache(): void
    {
        $this->components->task('Clearing response cache', function () {
            CacheResponse::clearAll();
            return true;
        });
    }

    /**
     * Clear page-related caches.
     */
    protected function clearPageCaches(): void
    {
        $this->components->task('Clearing page caches', function () {
            $this->clearPatternCaches([
                config('app-cache.keys.page', 'page') . ':*',
                config('app-cache.keys.page_breadcrumbs', 'page_breadcrumbs') . ':*',
                config('app-cache.keys.catalog_pages', 'catalog_pages') . ':*',
            ]);
            return true;
        });
    }

    /**
     * Clear navigation and search caches.
     */
    protected function clearNavigationCaches(): void
    {
        $this->components->task('Clearing navigation caches', function () {
            Cache::forget(config('app-cache.keys.navigation_tree'));
            Cache::forget(config('app-cache.keys.search_data'));
            Cache::forget(config('app-cache.keys.quick_responses'));
            return true;
        });
    }

    /**
     * Clear caches matching patterns.
     */
    protected function clearPatternCaches(array $patterns): void
    {
        if (config('cache.default') !== 'redis') {
            return;
        }

        $prefix = config('cache.prefix', '');
        $redis = Cache::getRedis();

        foreach ($patterns as $pattern) {
            $fullPattern = $prefix ? $prefix . ':' . $pattern : $pattern;
            $keys = $redis->keys($fullPattern);

            if (! empty($keys)) {
                foreach ($keys as $key) {
                    $cacheKey = $prefix ? str_replace($prefix . ':', '', $key) : $key;
                    Cache::forget($cacheKey);
                }
            }
        }
    }
}

