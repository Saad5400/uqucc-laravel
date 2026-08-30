<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opinion_poll_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opinion_poll_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('chat_id');
            $table->bigInteger('message_id');
            $table->bigInteger('message_thread_id')->nullable();
            $table->string('telegram_poll_id')->nullable();
            $table->json('votes')->nullable();
            $table->timestamp('posted_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['opinion_poll_id', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opinion_poll_posts');
    }
};
