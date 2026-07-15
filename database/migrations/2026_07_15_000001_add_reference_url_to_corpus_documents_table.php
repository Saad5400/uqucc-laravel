<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional citation-URL override on corpus documents. When null (the default),
 * the AI cites the built-in /mstnd/{document} route; when set, that URL is
 * handed to the AI verbatim instead — the model is told nothing about it being
 * an override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corpus_documents', function (Blueprint $table) {
            $table->string('reference_url')->nullable()->after('extracted_markdown');
        });
    }

    public function down(): void
    {
        Schema::table('corpus_documents', function (Blueprint $table) {
            $table->dropColumn('reference_url');
        });
    }
};
