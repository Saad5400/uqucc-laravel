<?php

namespace Tests;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refresh the application and drop vendor migrations that cannot run on sqlite.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->prunePostgresOnlyVendorMigrations();
    }

    /**
     * Remove the pgvector vendor migration path (CREATE EXTENSION is Postgres-only)
     * so RefreshDatabase can migrate the sqlite :memory: test database.
     */
    private function prunePostgresOnlyVendorMigrations(): void
    {
        $migrator = $this->app->make('migrator');

        (function (): void {
            /** @var Migrator $this */
            $this->paths = array_values(array_filter(
                $this->paths,
                fn (string $path): bool => ! str_contains($path, 'pgvector'),
            ));
        })->call($migrator);
    }
}
