<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The app's `admin_pending_actions` table is replaced by ai-kit's canonical
 * `ai_proposals` (written by the kit's ProposalExecutor). Move the rows over
 * KEEPING their ULIDs — stored conversations carry `proposal_id:` trailers
 * that must keep resolving to their cards — then drop the old table.
 *
 * `proposed_by` widens from a user FK to the kit's string owner key (the
 * same user id, stringified) and `category` is lifted out of the payload
 * into its own column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_pending_actions') || ! Schema::hasTable('ai_proposals')) {
            return;
        }

        DB::table('admin_pending_actions')->orderBy('id')->chunkById(500, function ($rows): void {
            DB::table('ai_proposals')->insert(
                $rows->map(function ($row): array {
                    $payload = json_decode((string) $row->payload, true);

                    return [
                        'id' => $row->id,
                        'type' => $row->type,
                        'category' => is_array($payload) ? ($payload['category'] ?? 'system') : 'system',
                        'payload' => $row->payload,
                        'summary' => $row->summary,
                        'status' => $row->status,
                        'proposed_by' => (string) $row->proposed_by,
                        'error' => $row->error,
                        'executed_at' => $row->executed_at,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                })->all(),
            );
        });

        Schema::drop('admin_pending_actions');
    }

    public function down(): void
    {
        // The import is one-way; the old table is gone.
    }
};
