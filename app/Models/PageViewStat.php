<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PageViewStat extends Model
{
    /** @use HasFactory<\Database\Factories\PageViewStatFactory> */
    use HasFactory;

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
     * The viewer identity one row stands for — the conflict target of the
     * upsert in {@see self::track()}. NULLs are COALESCEd because a unique
     * index treats NULLs as distinct; it must match the expressions of the
     * `page_view_stats_identity_unique` index exactly.
     */
    public const IDENTITY = "page_id, (COALESCE(user_id, 0)), (COALESCE(ip_address, ''))";

    /**
     * Count $views view(s) of a page by one viewer identity (page, user, ip):
     * a single upsert that creates the row or adds to it, keeping the latest
     * view time. The user agent is the one first seen for the identity.
     */
    public static function track(
        int $pageId,
        ?int $userId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        int $views = 1,
        ?CarbonInterface $viewedAt = null,
    ): void {
        $model = new static;
        $now = $model->fromDateTime(now());

        DB::statement(
            'INSERT INTO '.$model->getTable().' (page_id, user_id, ip_address, user_agent, view_count, last_viewed_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT ('.self::IDENTITY.') DO UPDATE SET
                view_count = '.$model->getTable().'.view_count + excluded.view_count,
                last_viewed_at = CASE WHEN excluded.last_viewed_at > '.$model->getTable().'.last_viewed_at
                    THEN excluded.last_viewed_at ELSE '.$model->getTable().'.last_viewed_at END,
                updated_at = excluded.updated_at',
            [
                $pageId,
                $userId,
                $ipAddress,
                $userAgent === null ? null : Str::limit($userAgent, 255, ''),
                $views,
                $viewedAt === null ? $now : $model->fromDateTime($viewedAt),
                $now,
                $now,
            ],
        );
    }

    /**
     * Turn a user's rows into guest rows before the user is deleted.
     *
     * `user_id` is ON DELETE SET NULL, which would leave each of the user's
     * rows a guest row for the same (page, ip) — a duplicate of the guest's own
     * row that the identity index rejects, failing the delete. Folding them in
     * first keeps every counted view, exactly as SET NULL meant to.
     */
    public static function foldIntoGuests(int $userId): void
    {
        static::query()->where('user_id', $userId)->each(function (PageViewStat $stat): void {
            static::track($stat->page_id, null, $stat->ip_address, $stat->user_agent, $stat->view_count, $stat->last_viewed_at);
            $stat->delete();
        });
    }
}
