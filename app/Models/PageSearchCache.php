<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageSearchCache extends Model
{
    protected $table = 'page_search_cache';

    protected $fillable = [
        'page_id',
        'section_id',
        'title',
        'content',
        'level',
        'position',
    ];

    protected $casts = [
        'level' => 'integer',
        'position' => 'integer',
    ];

    /**
     * Get the page that owns this search cache entry
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
