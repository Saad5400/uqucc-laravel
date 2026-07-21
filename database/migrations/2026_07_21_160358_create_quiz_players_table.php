<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_players', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_user_id')->unique();
            $table->string('first_name')->nullable();
            $table->string('username')->nullable();
            $table->string('major')->nullable();
            $table->unsignedInteger('total_points')->default(0);
            $table->unsignedInteger('weekly_points')->default(0);
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('best_streak')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('answers_count')->default(0);
            $table->date('last_answered_on')->nullable();
            $table->timestamps();

            $table->index('weekly_points');
            $table->index('total_points');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_players');
    }
};
