<?php

namespace App\Console\Commands;

use App\Services\MarkdownMigrationService;
use Illuminate\Console\Command;

class MigrateMarkdownContent extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'content:migrate
                            {--dry-run : Preview migration without saving to database}
                            {--path= : Specific path to migrate (relative to content directory)}
                            {--update-parents : Update parent relationships after migration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate markdown content from Nuxt to database';

    /**
     * Execute the console command.
     */
    public function handle(MarkdownMigrationService $service)
    {
        $this->info('🚀 Starting content migration from Nuxt to Laravel...');
        $this->newLine();

        $dryRun = $this->option('dry-run');
        $path = $this->option('path');

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN MODE - No data will be saved to database');
            $this->newLine();
        }

        if ($path) {
            $this->info("📁 Migrating specific path: {$path}");
        } else {
            $this->info('📁 Migrating all content files');
        }

        $this->newLine();

        // Run migration
        $results = $service->migrate($path, $dryRun);

        $this->newLine();
        $this->info("✅ Successfully migrated: {$results['success']} pages");

        if ($results['errors'] > 0) {
            $this->error("❌ Failed to migrate: {$results['errors']} pages");
        }

        // Update parent relationships if requested
        if ($this->option('update-parents') && ! $dryRun) {
            $this->newLine();
            $this->info('🔄 Updating parent relationships...');
            $service->updateParentRelationships();
        }

        $this->newLine();
        $this->info('✨ Migration complete!');

        return Command::SUCCESS;
    }
}
