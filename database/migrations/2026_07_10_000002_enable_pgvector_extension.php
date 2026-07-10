<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enable the pgvector extension for AI embeddings (Phase 0).
 *
 * The extension is enabled per-database, so this does not affect other
 * projects sharing the same Postgres cluster. The cluster itself must have
 * the pgvector binary installed once (e.g. `apt install postgresql-17-pgvector`
 * or the matching package for the server's Postgres version).
 *
 * Guards:
 *   - Silently skips on non-Postgres connections (local dev and the test
 *     suite run on sqlite, which has no vector type).
 *   - On Postgres it fails LOUDLY with an actionable message if the
 *     extension cannot be created, instead of letting a later `vector(...)`
 *     column error be the first symptom.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $available = DB::selectOne(
            "SELECT 1 AS ok FROM pg_available_extensions WHERE name = 'vector'"
        );

        if ($available === null) {
            throw new RuntimeException(
                'pgvector is not available in this PostgreSQL cluster. '
                .'The AI features require the "vector" extension. '
                .'Install the pgvector binary on the cluster once (e.g. '
                .'`apt install postgresql-17-pgvector` or the matching package '
                .'for your server) so this migration can run '
                .'`CREATE EXTENSION vector`, then retry `php artisan migrate`.'
            );
        }

        $installed = DB::selectOne(
            "SELECT 1 AS ok FROM pg_extension WHERE extname = 'vector'"
        );

        if ($installed !== null) {
            return;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Could not create the pgvector "vector" extension. It is available '
                .'in this cluster but not yet installed in this database, and the '
                .'application DB role lacks permission to CREATE EXTENSION (this '
                .'needs a superuser). Have a DBA run '
                .'`CREATE EXTENSION IF NOT EXISTS vector;` in this database once, '
                .'then retry `php artisan migrate`. Underlying error: '
                .$e->getMessage(),
                previous: $e,
            );
        }
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('DROP EXTENSION IF EXISTS vector');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
