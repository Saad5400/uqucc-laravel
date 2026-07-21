<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_topic_id')->nullable()->constrained()->nullOnDelete();
            $table->date('quiz_date')->unique();
            $table->text('question');
            $table->json('options');
            $table->unsignedTinyInteger('correct_option');
            $table->text('explanation')->nullable();
            $table->string('status')->default('ready');
            $table->string('telegram_poll_id')->nullable()->unique();
            $table->bigInteger('chat_id')->nullable();
            $table->bigInteger('message_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_quizzes');
    }
};
