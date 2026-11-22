<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PageViewStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PageViewStat extends Model
{
    /** @use HasFactory<PageViewStatFactory> */
    use HasFactory;

    protected $fillable = [
        'page_id',
        'user_id',
        'ip_address',
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
     * view time.
     *
     * The user agent is NOT stored: no report reads it (the recorder only uses
     * it to drop bots), so keeping it was personal data for nothing. The IP
     * stays — it is the guest's identity for the dedupe and the unique-visitor
     * counts. The column remains (always NULL) until a later migration drops it.
     */
    public static function track(
        int $pageId,
        ?int $userId = null,
        ?string $ipAddress = null,
        int $views = 1,
        ?CarbonInterface $viewedAt = null,
    ): void {
        $model = new static;
        $now = $model->fromDateTime(now());

        self::upsert('VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $pageId,
            $userId,
            $ipAddress,
            null,
            $views,
            $viewedAt === null ? $now : $model->fromDateTime($viewedAt),
            $now,
            $now,
        ]);
    }

    /**
     * Turn a user's rows into guest rows before the user is deleted.
     *
     * `user_id` is ON DELETE SET NULL, which would leave each of the user's
     * rows a guest row for the same (page, ip) — a duplicate of the guest's own
     * row that the identity index rejects, failing the delete. Folding them in
     * first keeps every counted view, exactly as SET NULL meant to. One
     * set-based upsert plus one delete, in a transaction, however many rows.
     */
    public static function foldIntoGuests(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            $table = (new static)->getTable();
            $folded = self::upsert(
                "SELECT page_id, NULL, ip_address, NULL, view_count, last_viewed_at, created_at, ? FROM {$table} WHERE user_id = ?",
                [(new static)->fromDateTime(now()), $userId],
            );

            // Without the identity index (see upsert()) nothing can collide:
            // ON DELETE SET NULL turns the rows into guest rows as it used to.
            if ($folded) {
                static::query()->where('user_id', $userId)->delete();
            }
        });
    }

    /**
     * INSERT the given rows (a VALUES list or a SELECT of the table's columns),
     * adding to the row of any identity that already exists.
     *
     * Degrades instead of failing when the identity index is missing (a
     * migration that did not complete): the statement is skipped and that is
     * logged once an hour — a page view must never turn into an error. Inside
     * a transaction it runs in a savepoint, so the failure does not abort it.
     *
     * @param  list<mixed>  $bindings
     * @return bool whether the rows were written
     */
    private static function upsert(string $rows, array $bindings): bool
    {
        $table = (new static)->getTable();
        $statement = fn (): bool => DB::statement(
            "INSERT INTO {$table} (page_id, user_id, ip_address, user_agent, view_count, last_viewed_at, created_at, updated_at)
            {$rows}
            ON CONFLICT (".self::IDENTITY.") DO UPDATE SET
                view_count = {$table}.view_count + excluded.view_count,
                last_viewed_at = CASE WHEN excluded.last_viewed_at > {$table}.last_viewed_at
                    THEN excluded.last_viewed_at ELSE {$table}.last_viewed_at END,
                updated_at = excluded.updated_at",
            $bindings,
        );

        try {
            DB::transactionLevel() > 0 ? DB::transaction($statement) : $statement();

            return true;
        } catch (QueryException $e) {
            if (! self::isMissingIdentityIndex($e)) {
                throw $e;
            }

            if (Cache::add('page_view_stats:identity-index-missing', true, now()->addHour())) {
                Log::error('page_view_stats has no identity index; page views are not being counted. Re-run the migration.', [
                    'error' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }

    private static function isMissingIdentityIndex(QueryException $e): bool
    {
        // Postgres: invalid_column_reference ("no unique or exclusion constraint
        // matching the ON CONFLICT specification"); SQLite has no code for it.
        return $e->getCode() === '42P10'
            || str_contains($e->getMessage(), 'ON CONFLICT clause does not match');
    }
}
