<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_players', function (Blueprint $table) {
            $table->date('streak_frozen_on')->nullable()->after('best_streak');
        });

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->index('answered_at');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->dropIndex(['answered_at']);
        });

        Schema::table('quiz_players', function (Blueprint $table) {
            $table->dropColumn('streak_frozen_on');
        });
    }
};
