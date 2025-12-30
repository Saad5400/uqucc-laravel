<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CleanupOrphanedCaches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup-caches
                            {--screenshots-days=14 : Days to keep screenshots}
                            {--dry-run : Show what would be deleted without deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up orphaned screenshot files to free disk space';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $screenshotsDays = (int) $this->option('screenshots-days');

        if ($dryRun) {
            $this->info('DRY RUN - No files will be deleted');
        }

        $this->cleanupOrphanedScreenshots($screenshotsDays, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * Clean up orphaned screenshot files older than the specified days.
     */
    protected function cleanupOrphanedScreenshots(int $days, bool $dryRun): void
    {
        $screenshotsDir = storage_path('app/public/screenshots');

        if (! is_dir($screenshotsDir)) {
            $this->info('Screenshots directory does not exist');

            return;
        }

        $files = glob($screenshotsDir.'/*.webp');
        $deletedCount = 0;
        $deletedSize = 0;
        $cutoffTime = time() - ($days * 24 * 60 * 60);

        $this->info("Checking screenshots older than {$days} days...");

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $fileTime = filemtime($file);
            if ($fileTime && $fileTime < $cutoffTime) {
                $size = filesize($file);
                $basename = basename($file);

                if ($dryRun) {
                    $this->line("  Would delete: {$basename} (".round($size / 1024, 2).' KB)');
                } else {
                    if (@unlink($file)) {
                        $deletedCount++;
                        $deletedSize += $size;
                        $this->line("  Deleted: {$basename}");
                    }
                }
            }
        }

        $freedMb = round($deletedSize / 1024 / 1024, 2);

        if ($dryRun) {
            $this->info("Would delete {$deletedCount} files ({$freedMb} MB)");
        } else {
            $this->info("Deleted {$deletedCount} screenshot files, freed {$freedMb} MB");
        }
    }
}
