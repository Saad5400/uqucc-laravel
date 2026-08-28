<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly board is summed from the answer trail per quiz day now
 * ({@see App\Services\Quiz\QuizLeaderboard}), so this counter has no reader
 * left. It was also the bug: it was incremented at vote time and zeroed in
 * bulk by the weekly announcement, which fell in the middle of the night the
 * live question was still taking votes — wiping the points of everyone who
 * answered early and crediting the identical late answers to the new week.
 *
 * Nothing is lost: the column only ever held the current week, and every
 * point in it is in `quiz_answers`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_players', function (Blueprint $table) {
            $table->dropIndex(['weekly_points']);
            $table->dropColumn('weekly_points');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_players', function (Blueprint $table) {
            $table->unsignedInteger('weekly_points')->default(0)->after('total_points');
            $table->index('weekly_points');
        });
    }
};
