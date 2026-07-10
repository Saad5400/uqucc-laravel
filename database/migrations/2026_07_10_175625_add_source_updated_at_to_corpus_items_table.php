<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * corpus_items.source_updated_at — when the SOURCE (the CMS page or uploaded
 * document) was last updated, copied in at ingest time. It is the freshness
 * signal retrieval threads through to search results and AI tools so stale
 * content can be flagged; nullable because rows ingested before this column
 * existed backfill on their next ingest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corpus_items', function (Blueprint $table) {
            $table->timestamp('source_updated_at')->nullable()->after('meta');
        });
    }

    public function down(): void
    {
        Schema::table('corpus_items', function (Blueprint $table) {
            $table->dropColumn('source_updated_at');
        });
    }
};
