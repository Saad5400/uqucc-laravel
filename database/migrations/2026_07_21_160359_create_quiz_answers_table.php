<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_player_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('selected_option');
            $table->boolean('is_correct');
            $table->unsignedInteger('points');
            $table->unsignedInteger('streak_at_answer');
            $table->timestamp('answered_at');
            $table->timestamps();

            $table->unique(['daily_quiz_id', 'quiz_player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_answers');
    }
};
