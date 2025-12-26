<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Page extends Model implements Sortable
{
    use SoftDeletes;
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
        Cache::forget(config('app-cache.keys.navigation_tree'));
        Cache::forget(config('app-cache.keys.search_data'));
        Cache::forget(config('app-cache.keys.quick_responses'));
    }

    /**
     * Clear screenshot cache for this specific page
     */
    public function clearScreenshotCache(): void
    {
        // When a page is updated, the updated_at timestamp changes
        // This means the cache key will be different (includes timestamp)
        // So old cache entries will naturally expire, but we clean up old screenshot files for this page

        $slug = str_replace('/', '_', trim($this->slug, '/')) ?: 'home';
        $screenshotsDir = storage_path('app/public/screenshots');

        if (is_dir($screenshotsDir)) {
            // Delete all screenshot files for this page (regardless of version)
            $pattern = $screenshotsDir.'/'.preg_quote($slug, '/').'_*.webp';
            $files = glob($pattern);

            foreach ($files as $file) {
                @unlink($file);
            }
        }

        // Note: We don't need to manually clear cache entries because:
        // 1. Cache keys include updated_at timestamp, so new updates get new keys
        // 2. Old cache entries expire naturally based on TTL
        // 3. The file-based check in takeScreenshot ensures we don't use stale files
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
        'parent_id',
        'level',
        'extension',
        'quick_response_auto_extract',
        'quick_response_customize_message',
        'quick_response_customize_buttons',
        'quick_response_customize_attachments',
        'quick_response_send_link',
        'quick_response_require_prefix',
        'quick_response_message',
        'quick_response_buttons',
        'quick_response_attachments',
    ];

    protected $casts = [
        'hidden' => 'boolean',
        'hidden_from_bot' => 'boolean',
        'smart_search' => 'boolean',
        'order' => 'integer',
        'level' => 'integer',
        'quick_response_auto_extract' => 'boolean',
        'quick_response_customize_message' => 'boolean',
        'quick_response_customize_buttons' => 'boolean',
        'quick_response_customize_attachments' => 'boolean',
        'quick_response_send_link' => 'boolean',
        'quick_response_require_prefix' => 'boolean',
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
     * Get the authors for this page
     */
    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class)->withPivot('order')->withTimestamps()->orderBy('order');
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
}
