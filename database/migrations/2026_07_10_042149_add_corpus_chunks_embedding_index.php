<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ANN index on corpus_chunks.embedding — pgsql only.
 *
 * HNSW over IVFFlat because it needs no training pass or row-count tuning: it
 * can be built on an empty table and stays correct as the corpus grows with
 * every page edit. `vector_cosine_ops` matches CorpusRetriever's cosine
 * (`<=>`) ordering — the index only accelerates a query whose operator matches
 * its opclass. Skipped entirely on sqlite (embeddings there are JSON text)
 * and idempotent via IF NOT EXISTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS corpus_chunks_embedding_hnsw_idx '
            .'ON corpus_chunks USING hnsw (embedding vector_cosine_ops)'
        );
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS corpus_chunks_embedding_hnsw_idx');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
