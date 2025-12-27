<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageViewStat extends Model
{
    protected $fillable = [
        'page_id',
        'user_id',
        'ip_address',
        'user_agent',
        'view_count',
        'last_viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }

    /**
     * Get the page that was viewed
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Get the user who viewed the page
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Track a page view
     */
    public static function track(int $pageId, ?int $userId = null, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $stat = static::where('page_id', $pageId)
            ->where('user_id', $userId)
            ->where('ip_address', $ipAddress)
            ->first();

        if ($stat) {
            $stat->increment('view_count');
            $stat->update(['last_viewed_at' => now()]);
        } else {
            static::create([
                'page_id' => $pageId,
                'user_id' => $userId,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'view_count' => 1,
                'last_viewed_at' => now(),
            ]);
        }
    }
}
