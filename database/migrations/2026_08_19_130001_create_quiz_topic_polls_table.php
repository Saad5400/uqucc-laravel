<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One "which topic should tomorrow's question be about?" vote — the ballot that
 * goes out right under the day's question and is tallied when tomorrow's
 * question is generated. One row per decided day.
 *
 * `posts` holds the delivery receipts (chat, message, poll id) rather than a
 * child table: they are opaque handles used only to stop the polls again as a
 * set, never queried on their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_topic_polls', function (Blueprint $table) {
            $table->id();
            $table->date('quiz_date')->unique();
            $table->json('topic_ids');
            $table->json('posts');
            $table->foreignId('quiz_topic_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_topic_polls');
    }
};
