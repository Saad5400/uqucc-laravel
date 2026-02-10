<?php

namespace App\Models;

use App\Http\Middleware\CacheResponse;
use App\Services\OgImageService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Page extends Model implements Sortable
{
    use LogsActivity, SoftDeletes;
    use SortableTrait;

    protected static function booted(): void
    {
        static::saved(function (Page $page) {
            self::clearAppCache();
            $page->clearScreenshotCache();
        });

        static::deleted(function () {
            self::clearAppCache();
        });

        static::restored(function () {
            self::clearAppCache();
        });
    }

    protected static function clearAppCache(): void
    {
        // Clear navigation and response cache immediately
        Cache::forget(config('app-cache.keys.navigation_tree'));
        Cache::forget(config('app-cache.keys.response_cache'));

        // Dispatch background jobs for search index and quick responses
        // These are CPU and memory intensive, so they run asynchronously
        dispatch(new \App\Jobs\RebuildSearchIndex);
        dispatch(new \App\Jobs\RebuildQuickResponses);

        // Clear page-specific caches using pattern-based flush
        // This uses Redis SCAN to find and delete matching keys efficiently
        self::clearPageCaches();
    }

    /**
     * Clear all page-related caches (pages, breadcrumbs, catalogs, response cache).
     */
    protected static function clearPageCaches(): void
    {
        $patterns = [
            config('app-cache.keys.page', 'page').':*',
            config('app-cache.keys.page_breadcrumbs', 'page_breadcrumbs').':*',
            config('app-cache.keys.catalog_pages', 'catalog_pages').':*',
            config('app-cache.keys.response_cache', 'response_cache').':*',
        ];

        // Get the cache prefix
        $prefix = config('cache.prefix', '');

        // For Redis, use pattern-based deletion
        if (config('cache.default') === 'redis') {
            $redis = Cache::getRedis();
            foreach ($patterns as $pattern) {
                $fullPattern = $prefix ? $prefix.':'.$pattern : $pattern;
                $keys = $redis->keys($fullPattern);
                if (! empty($keys)) {
                    // Remove prefix from keys before forgetting
                    foreach ($keys as $key) {
                        $cacheKey = $prefix ? str_replace($prefix.':', '', $key) : $key;
                        Cache::forget($cacheKey);
                    }
                }
            }
        }

        // Also clear response cache using the middleware helper (works for non-Redis too)
        CacheResponse::clearAll();
    }

    /**
     * Clear screenshot cache for this specific page
     */
    public function clearScreenshotCache(): void
    {
        $ogImageService = app(OgImageService::class);
        $ogImageService->clearPageCache($this);
        $ogImageService->clearOldScreenshots($this->slug);
    }

    protected $fillable = [
        'slug',
        'title',
        'html_content',
        'order',
        'icon',
        'hidden',
        'hidden_from_bot',
        'smart_search',
        'requires_prefix',
        'parent_id',
        'level',
        'extension',
        'quick_response_auto_extract_message',
        'quick_response_auto_extract_buttons',
        'quick_response_auto_extract_attachments',
        'quick_response_send_link',
        'quick_response_send_screenshot',
        'quick_response_message',
        'quick_response_buttons',
        'quick_response_attachments',
    ];

    protected $casts = [
        'hidden' => 'boolean',
        'hidden_from_bot' => 'boolean',
        'smart_search' => 'boolean',
        'requires_prefix' => 'boolean',
        'order' => 'integer',
        'level' => 'integer',
        'quick_response_auto_extract_message' => 'boolean',
        'quick_response_auto_extract_buttons' => 'boolean',
        'quick_response_auto_extract_attachments' => 'boolean',
        'quick_response_send_link' => 'boolean',
        'quick_response_send_screenshot' => 'boolean',
        'quick_response_buttons' => 'array',
        'quick_response_attachments' => 'array',
    ];

    public array $sortable = [
        'order_column_name' => 'order',
        'sort_when_creating' => true,
    ];

    public function buildSortQuery()
    {
        return static::query()->where('parent_id', $this->parent_id);
    }

    /**
     * Get the parent page
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'parent_id');
    }

    /**
     * Get the child pages
     */
    public function children(): HasMany
    {
        return $this->hasMany(Page::class, 'parent_id')->orderBy('order');
    }

    /**
     * Get the users (authors) for this page
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('order')->withTimestamps()->orderBy('order');
    }

    protected function htmlContent(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                // If JSON decode succeeds, return the array; otherwise return the original string HTML.
                if (blank($value)) {
                    return $value;
                }

                $decoded = json_decode($value, true);

                return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $value;
            },
            set: fn ($value) => is_array($value) ? json_encode($value) : $value,
        );
    }

    /**
     * Scope to filter only visible pages (website)
     */
    public function scopeVisible($query)
    {
        return $query->where('hidden', false);
    }

    /**
     * Scope to filter pages visible in Telegram bot
     */
    public function scopeVisibleInBot($query)
    {
        return $query->where('hidden_from_bot', false);
    }

    /**
     * Scope to get smart search enabled pages
     */
    public function scopeSmartSearch($query)
    {
        return $query->where('smart_search', true);
    }

    /**
     * Scope to get root level pages
     */
    public function scopeRootLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Configure activity logging options
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'slug',
                'title',
                'html_content',
                'order',
                'icon',
                'hidden',
                'hidden_from_bot',
                'smart_search',
                'requires_prefix',
                'parent_id',
                'level',
                'extension',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
