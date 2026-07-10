<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * corpus_documents — admin-uploaded knowledge files (regulations, guides)
 * destined for the AI retrieval corpus.
 *
 * One row per uploaded file: where the bytes live (disk + path), what was
 * extracted from them (extracted_markdown), and the extraction lifecycle
 * (pending → extracting → ready | failed). The ingested representation lives
 * in corpus_items/corpus_chunks keyed by (source_type "document", source_id =
 * this id) — deleting a document evicts its corpus item. New table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corpus_documents', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->string('original_filename');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->string('status')->default('pending');
            $table->longText('extracted_markdown')->nullable();
            $table->text('error')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corpus_documents');
    }
};
