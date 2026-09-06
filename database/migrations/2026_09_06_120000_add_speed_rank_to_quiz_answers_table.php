<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a correct answer landed in the race for the day's question — 1 for the
 * first, null once the bonus ranks are gone. Stored rather than derived from
 * `answered_at`, so the points on the row can always be explained by the row
 * itself; see {@see App\Services\Quiz\QuizAnswerRecorder}.
 *
 * Existing answers keep null: nobody was racing when they were recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->unsignedTinyInteger('speed_rank')->nullable()->after('streak_at_answer');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->dropColumn('speed_rank');
        });
    }
};
