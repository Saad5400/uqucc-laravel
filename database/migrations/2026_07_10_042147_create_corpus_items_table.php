<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * corpus_items — one ingestible knowledge source for AI retrieval.
 *
 * Polymorphic-ish by (source_type, source_id): today source_type = "page"
 * pointing at pages.id; uploaded documents plug in later as a new source_type
 * without schema changes. `checksum` is the idempotency key — re-ingesting
 * unchanged content is a no-op. New table only; touches nothing existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corpus_items', function (Blueprint $table) {
            $table->id();

            $table->string('source_type');
            $table->unsignedBigInteger('source_id');

            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('lang')->nullable();

            $table->string('status')->default('pending');

            $table->string('checksum')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corpus_items');
    }
};
