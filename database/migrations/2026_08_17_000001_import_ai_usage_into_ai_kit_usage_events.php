<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The app's hand-rolled `ai_usage` spend ledger is replaced by ai-kit's
 * canonical `ai_usage_events` table (recorded automatically per turn by the
 * usage module). Import the historical rows so all-time / by-feature spend
 * analytics keep their history, then drop the old table.
 *
 * Old rows carry no invocation id, provider, or stream flag; they are
 * imported with a fresh uuid, the app's only provider, and
 * `cost_source: imported` so they remain distinguishable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_usage') || ! Schema::hasTable('ai_usage_events')) {
            return;
        }

        DB::table('ai_usage')->orderBy('id')->chunkById(500, function ($rows): void {
            DB::table('ai_usage_events')->insert(
                $rows->map(fn ($row): array => [
                    'invocation_id' => (string) Str::uuid(),
                    'feature' => $row->feature,
                    'provider' => 'openrouter',
                    'model' => $row->model,
                    'streamed' => false,
                    'prompt_tokens' => (int) ($row->prompt_tokens ?? 0),
                    'completion_tokens' => (int) ($row->completion_tokens ?? 0),
                    'cost_usd' => $row->cost,
                    'cost_source' => 'imported',
                    'status' => 'ok',
                    'created_at' => $row->created_at,
                ])->all(),
            );
        });

        Schema::drop('ai_usage');
    }

    public function down(): void
    {
        // The import is one-way; the old table is gone. Imported rows stay
        // identifiable via cost_source = 'imported'.
    }
};
