<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-chat AI activation for the Telegram bot. The assistant is OFF by
 * default everywhere; a row with ai_enabled=true (created via /ai_on in the
 * chat or toggled from the admin panel) activates it for that chat only.
 * conversation_id stores the laravel/ai thread the chat is continuing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_chat_settings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->unique();
            $table->boolean('ai_enabled')->default(false);
            $table->string('title')->nullable();
            $table->string('type', 20)->nullable();
            $table->string('enabled_by')->nullable();
            $table->string('conversation_id', 36)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_chat_settings');
    }
};
