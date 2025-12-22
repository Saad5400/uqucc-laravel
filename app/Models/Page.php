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
        static::saved(function () {
            self::clearAppCache();
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
    }

    protected $fillable = [
        'slug',
        'title',
        'description',
        'html_content',
        'order',
        'icon',
        'og_image',
        'hidden',
        'parent_id',
        'level',
        'stem',
        'extension',
    ];

    protected $casts = [
        'hidden' => 'boolean',
        'order' => 'integer',
        'level' => 'integer',
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
        return $this->belongsToMany(Author::class)->withTimestamps()->orderBy('order');
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
     * Scope to filter only visible pages
     */
    public function scopeVisible($query)
    {
        return $query->where('hidden', false);
    }

    /**
     * Scope to get root level pages
     */
    public function scopeRootLevel($query)
    {
        return $query->whereNull('parent_id');
    }
}
