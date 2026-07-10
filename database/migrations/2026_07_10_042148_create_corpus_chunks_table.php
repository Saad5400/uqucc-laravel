<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * corpus_chunks — the retrieval unit: one chunk of a corpus item plus its
 * embedding and an Arabic-normalized copy of the text for the keyword leg.
 *
 * The embedding column type is connection-dependent:
 *   - pgsql: `vector(N)` via the pgvector blueprint macro registered in
 *     AppServiceProvider. N comes from config('ai.embeddings.dimensions') and
 *     must stay in lock-step with the embedding model (changing it later is a
 *     re-embed migration).
 *   - sqlite/other (local dev + tests): a nullable text column holding the
 *     JSON float list. CorpusRetriever never runs vector SQL off pgsql, so
 *     tests exercise the keyword path on the in-memory harness.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPostgres = Schema::getConnection()->getDriverName() === 'pgsql';
        $dimensions = (int) config('ai.embeddings.dimensions', 1536);

        Schema::create('corpus_chunks', function (Blueprint $table) use ($isPostgres, $dimensions) {
            $table->id();

            $table->foreignId('corpus_item_id')
                ->constrained('corpus_items')
                ->cascadeOnDelete();

            $table->integer('chunk_index');
            $table->string('heading')->nullable();
            $table->text('content');
            $table->text('normalized_content');
            $table->integer('token_count')->nullable();

            if ($isPostgres) {
                $table->vector('embedding', $dimensions)->nullable();
            } else {
                $table->text('embedding')->nullable();
            }

            $table->timestamps();

            $table->index(['corpus_item_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corpus_chunks');
    }
};
