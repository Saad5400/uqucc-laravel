<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A review-gated content revision the page-authoring AI proposed for an
 * EXISTING page from an uploaded corpus document. The live page is never
 * touched until an admin applies the proposal from the review screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_content_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('corpus_document_id')->constrained()->cascadeOnDelete();
            $table->longText('proposed_markdown');
            $table->json('proposed_html_content')->nullable();
            $table->text('summary');
            $table->string('status')->default('pending')->index();
            $table->text('error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_content_proposals');
    }
};
