<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * corpus_image_extractions — a permanent cache of vision-model transcriptions
 * of images embedded in CMS pages.
 *
 * Keyed by `content_hash`: the sha-256 of the image FILE bytes when the image
 * is locally resolvable (a /storage/ upload), else of its URL. Re-ingesting a
 * page whose images have not changed hits this cache and never re-OCRs, so
 * page saves stay cheap. `status` records why no text exists: "failed" rows
 * are retried on later ingests, "skipped" marks external-host images that are
 * never fetched. New table only; touches nothing existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corpus_image_extractions', function (Blueprint $table) {
            $table->id();

            $table->string('content_hash', 64)->unique();
            $table->text('source_url');

            $table->text('extracted_text')->nullable();
            $table->string('model')->nullable();
            $table->string('status')->default('extracted');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corpus_image_extractions');
    }
};
