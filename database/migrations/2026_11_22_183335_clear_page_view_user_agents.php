<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Page views no longer store the visitor's user agent (no report reads it;
 * PageViewStat::track()). This clears the ones already stored, in batches by
 * id, each committed on its own, so no single statement or transaction
 * rewrites the whole table at once. The column stays (always NULL) so a
 * container still on the previous release during a rolling deploy can keep
 * inserting; a later migration can drop it.
 */
return new class extends Migration
{
    /**
     * Postgres runs a migration inside one transaction by default, which
     * would make the batches below one long transaction after all. Without
     * it each batch's UPDATE commits on its own.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // The common case after the first deploy (or on a fresh database):
        // nothing to clear — one EXISTS query and out.
        if (! DB::table('page_view_stats')->whereNotNull('user_agent')->exists()) {
            return;
        }

        $lastId = 0;

        do {
            $ids = DB::table('page_view_stats')
                ->where('id', '>', $lastId)
                ->whereNotNull('user_agent')
                ->orderBy('id')
                ->limit(5000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            DB::table('page_view_stats')->whereIn('id', $ids)->update(['user_agent' => null]);
            $lastId = (int) $ids->last();
        } while (true);
    }

    public function down(): void
    {
        // The agents are gone; nothing to restore.
    }
};
