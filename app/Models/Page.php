<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Page extends Model implements Sortable
{
    use SoftDeletes;
    use SortableTrait;

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

    /**
     * Get the search cache sections for this page
     */
    public function searchCacheSections(): HasMany
    {
        return $this->hasMany(PageSearchCache::class);
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
