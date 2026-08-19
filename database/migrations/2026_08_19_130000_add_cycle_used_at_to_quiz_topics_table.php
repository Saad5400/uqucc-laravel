<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a topic as already covered by the current rotation cycle. `last_used_at`
 * alone cannot say that: it survives forever, while this is cleared for the
 * whole pool the moment every topic in it has had a turn — which is what lets
 * the group vote on tomorrow's topic without any topic being skipped.
 *
 * Left null for existing topics on purpose: the first cycle starts now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_topics', function (Blueprint $table) {
            $table->timestamp('cycle_used_at')->nullable()->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_topics', function (Blueprint $table) {
            $table->dropColumn('cycle_used_at');
        });
    }
};
