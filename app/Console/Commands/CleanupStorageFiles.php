<?php

namespace App\Console\Commands;

use App\Support\ScreenshotConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanupStorageFiles extends Command
{
    protected $signature = 'storage:cleanup
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--screenshots : Clean up old/orphaned screenshots}
                            {--temp-attachments : Clean up old temp cache attachments}
                            {--logs : Clean up old log files}
                            {--all : Clean up everything}';

    protected $description = 'Clean up orphaned and temporary files from storage to free disk space';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $all = $this->option('all');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be deleted');
        }

        $totalFreed = 0;

        if ($all || $this->option('screenshots')) {
            $totalFreed += $this->cleanupScreenshots($dryRun);
        }

        if ($all || $this->option('temp-attachments')) {
            $totalFreed += $this->cleanupTempAttachments($dryRun);
        }

        if ($all || $this->option('logs')) {
            $totalFreed += $this->cleanupLogs($dryRun);
        }

        $this->newLine();
        $this->info(sprintf(
            'Total space %s: %s',
            $dryRun ? 'that would be freed' : 'freed',
            $this->formatBytes($totalFreed)
        ));

        return self::SUCCESS;
    }

    /**
     * Clean up orphaned screenshot files that no longer have valid cache entries.
     */
    protected function cleanupScreenshots(bool $dryRun): int
    {
        $this->info('Cleaning up orphaned screenshots...');

        $screenshotsDir = ScreenshotConfig::directory();
        if (! is_dir($screenshotsDir)) {
            $this->line('  Screenshots directory does not exist.');

            return 0;
        }

        $extension = ScreenshotConfig::extension();
        $escapedExtension = preg_quote($extension, '/');
        $files = glob($screenshotsDir.'/*.'.$extension);
        $deletedCount = 0;
        $freedBytes = 0;

        foreach ($files as $file) {
            $filename = basename($file);

            // Check if this file has a corresponding cache entry
            // Files are named: {type}_{identifier}.{extension}
            // The cache key format is: screenshot:{type}:{identifier}
            if (preg_match('/^(bot|og)_(.+)\.'.$escapedExtension.'$/', $filename, $matches)) {
                $type = $matches[1];
                $identifier = $matches[2];
                $cacheKey = config('app-cache.keys.screenshot').":{$type}:{$identifier}";

                // If no cache entry exists, this file is orphaned
                if (! \Illuminate\Support\Facades\Cache::has($cacheKey)) {
                    $size = filesize($file);
                    $freedBytes += $size;
                    $deletedCount++;

                    if (! $dryRun) {
                        @unlink($file);
                    }

                    $this->line(sprintf('  %s %s (%s)',
                        $dryRun ? 'Would delete:' : 'Deleted:',
                        $filename,
                        $this->formatBytes($size)
                    ));
                }
            }
        }

        $this->info(sprintf('  %s %d orphaned screenshot(s), freeing %s',
            $dryRun ? 'Would delete' : 'Deleted',
            $deletedCount,
            $this->formatBytes($freedBytes)
        ));

        return $freedBytes;
    }

    /**
     * Clean up old temporary attachment cache files.
     * These are in the old cache location that we're migrating away from.
     */
    protected function cleanupTempAttachments(bool $dryRun): int
    {
        $this->info('Cleaning up temporary attachment cache...');

        $cacheDir = storage_path('app/cache/external-attachments');
        if (! is_dir($cacheDir)) {
            $this->line('  Temp attachments directory does not exist.');

            return 0;
        }

        $files = glob($cacheDir.'/*');
        $deletedCount = 0;
        $freedBytes = 0;

        foreach ($files as $file) {
            if (is_file($file)) {
                $size = filesize($file);
                $freedBytes += $size;
                $deletedCount++;

                if (! $dryRun) {
                    @unlink($file);
                }

                $this->line(sprintf('  %s %s (%s)',
                    $dryRun ? 'Would delete:' : 'Deleted:',
                    basename($file),
                    $this->formatBytes($size)
                ));
            }
        }

        // Remove empty directory
        if (! $dryRun && is_dir($cacheDir) && count(glob($cacheDir.'/*')) === 0) {
            @rmdir($cacheDir);
            $this->line('  Removed empty cache directory.');
        }

        $this->info(sprintf('  %s %d temp attachment(s), freeing %s',
            $dryRun ? 'Would delete' : 'Deleted',
            $deletedCount,
            $this->formatBytes($freedBytes)
        ));

        return $freedBytes;
    }

    /**
     * Clean up old log files, keeping only recent ones.
     */
    protected function cleanupLogs(bool $dryRun): int
    {
        $this->info('Cleaning up old log files...');

        $logsDir = storage_path('logs');
        if (! is_dir($logsDir)) {
            $this->line('  Logs directory does not exist.');

            return 0;
        }

        $files = glob($logsDir.'/laravel-*.log');
        $deletedCount = 0;
        $freedBytes = 0;

        // Keep logs from the last 7 days
        $cutoffDate = now()->subDays(7);

        foreach ($files as $file) {
            $filename = basename($file);

            // Parse date from filename (laravel-YYYY-MM-DD.log)
            if (preg_match('/laravel-(\d{4}-\d{2}-\d{2})\.log/', $filename, $matches)) {
                try {
                    $fileDate = \Carbon\Carbon::parse($matches[1]);

                    if ($fileDate->lt($cutoffDate)) {
                        $size = filesize($file);
                        $freedBytes += $size;
                        $deletedCount++;

                        if (! $dryRun) {
                            @unlink($file);
                        }

                        $this->line(sprintf('  %s %s (%s)',
                            $dryRun ? 'Would delete:' : 'Deleted:',
                            $filename,
                            $this->formatBytes($size)
                        ));
                    }
                } catch (\Exception $e) {
                    // Skip files with unparseable dates
                }
            }
        }

        $this->info(sprintf('  %s %d old log file(s), freeing %s',
            $dryRun ? 'Would delete' : 'Deleted',
            $deletedCount,
            $this->formatBytes($freedBytes)
        ));

        return $freedBytes;
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $value = $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return sprintf('%.2f %s', $value, $units[$index]);
    }
}
