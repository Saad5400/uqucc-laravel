<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_quiz_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('chat_id');
            $table->bigInteger('message_id');
            $table->string('telegram_poll_id')->unique();
            $table->timestamp('posted_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['daily_quiz_id', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_posts');
    }
};
