<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files visitors attach to the AI chat. Session-owned (no accounts) and never
 * part of the public corpus: the extracted markdown is injected only into the
 * owning session's conversation. Pruned together with old conversations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('session_id', 64)->index();
            $table->string('conversation_id', 36)->nullable()->index();
            $table->string('original_filename');
            $table->string('disk');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status', 20)->default('pending');
            $table->longText('extracted_markdown')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_attachments');
    }
};
