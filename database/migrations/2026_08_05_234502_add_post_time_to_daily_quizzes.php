<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A one-off posting time for a single day, as «HH:MM». Null means the
     * question goes out at the `quiz.post_time` default like every other day.
     */
    public function up(): void
    {
        Schema::table('daily_quizzes', function (Blueprint $table) {
            $table->string('post_time', 5)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('daily_quizzes', function (Blueprint $table) {
            $table->dropColumn('post_time');
        });
    }
};
