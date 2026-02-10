<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            // Composite index for visible pages sorted by title
            $table->index(['hidden', 'title'], 'idx_visible_title');

            // Index for parent_id to speed up breadcrumb lookups
            $table->index(['parent_id'], 'idx_parent');

            // Index for smart_search filtering
            $table->index(['smart_search', 'hidden'], 'idx_smart_search');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex('idx_visible_title');
            $table->dropIndex('idx_parent');
            $table->dropIndex('idx_smart_search');
        });
    }
};
