<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Author extends Model
{
    protected $fillable = [
        'username',
        'name',
        'url',
        'avatar',
    ];

    /**
     * Get the pages authored by this author
     */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class)->withPivot('order')->withTimestamps();
    }
}
