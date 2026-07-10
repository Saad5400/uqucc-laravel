<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The laravel/ai conversation tables, created by our own migration instead of
 * publishing the vendor one for a single load-bearing reason: the site has no
 * user accounts, so `user_id` holds the ANONYMOUS owner key (the visitor's
 * session id, or "telegram:<chat id>" for the bot) and must be a string —
 * the vendor migration types it foreignId (bigint), which would reject those
 * keys on Postgres. Everything else matches the vendor schema exactly so the
 * package's DatabaseConversationStore works unmodified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_conversations', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('user_id', 64)->nullable();
            $table->string('title');
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('agent_conversation_messages', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->string('user_id', 64)->nullable();
            $table->string('agent');
            $table->string('role', 25);
            $table->text('content');
            $table->text('attachments');
            $table->text('tool_calls');
            $table->text('tool_results');
            $table->text('usage');
            $table->text('meta');
            $table->timestamps();

            $table->index(['conversation_id', 'user_id', 'updated_at'], 'conversation_index');
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_conversation_messages');
        Schema::dropIfExists('agent_conversations');
    }
};
