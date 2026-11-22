<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per viewer identity — (page, user, ip) — enforced by a unique index,
 * so PageViewStat::track() can count a view with a single upsert instead of a
 * SELECT on an unindexed ip_address followed by two UPDATEs.
 *
 * user_id and ip_address are nullable, and a plain unique index treats NULLs as
 * distinct (every guest view would be "new"), so the index is on COALESCEd
 * expressions — the same on Postgres and SQLite. The expression list must stay
 * identical to PageViewStat::IDENTITY, which names it as the upsert's conflict
 * target.
 *
 * Rows that already share an identity (concurrent first views, or a deleted
 * user's rows set to NULL) are merged first: views summed, the latest view and
 * the first sighting kept. All-time totals are unchanged; the windowed ones
 * (views30d, the daily chart) rise once, because a merged row's older views
 * now sit on its latest last_viewed_at.
 *
 * On Postgres the table is locked against writes (SHARE ROW EXCLUSIVE) for
 * the whole migration, which Laravel runs in one transaction there: during a
 * rolling deploy the old container keeps inserting, and a duplicate written
 * between the merge and CREATE UNIQUE INDEX would fail the index. Its inserts
 * wait for the commit instead (the merge and the index build take moments).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE page_view_stats IN SHARE ROW EXCLUSIVE MODE');
            }

            $duplicates = DB::table('page_view_stats')
                ->select('page_id', 'user_id', 'ip_address')
                ->selectRaw('MIN(id) as keep_id, SUM(view_count) as views, MAX(last_viewed_at) as last_viewed_at, MIN(created_at) as created_at')
                ->groupBy('page_id', 'user_id', 'ip_address')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicates as $group) {
                DB::table('page_view_stats')->where('id', $group->keep_id)->update([
                    'view_count' => (int) $group->views,
                    'last_viewed_at' => $group->last_viewed_at,
                    'created_at' => $group->created_at,
                ]);

                DB::table('page_view_stats')
                    ->where('page_id', $group->page_id)
                    ->where(fn ($query) => $group->user_id === null ? $query->whereNull('user_id') : $query->where('user_id', $group->user_id))
                    ->where(fn ($query) => $group->ip_address === null ? $query->whereNull('ip_address') : $query->where('ip_address', $group->ip_address))
                    ->where('id', '!=', $group->keep_id)
                    ->delete();
            }

            DB::statement("CREATE UNIQUE INDEX page_view_stats_identity_unique ON page_view_stats (page_id, (COALESCE(user_id, 0)), (COALESCE(ip_address, '')))");
        });

        // Subsumed by the identity index (same leading column); one index less
        // to maintain on every view.
        Schema::table('page_view_stats', function (Blueprint $table): void {
            $table->dropIndex(['page_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('page_view_stats', function (Blueprint $table): void {
            $table->index(['page_id', 'user_id']);
        });

        DB::statement('DROP INDEX page_view_stats_identity_unique');
    }
};
