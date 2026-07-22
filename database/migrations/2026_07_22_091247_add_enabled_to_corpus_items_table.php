<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * corpus_items.enabled — the admin's retrieval switch for a document. When
 * false the item is kept whole (file, extracted text, chunks and embeddings)
 * but excluded from every AI retrieval path, so a regulation can be taken
 * offline and brought back instantly without a re-ingest. Defaults to true so
 * existing items and always-on page sources stay retrievable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corpus_items', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('corpus_items', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }
};
