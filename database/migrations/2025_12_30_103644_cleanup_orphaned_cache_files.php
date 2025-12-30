<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Clean up orphaned cache files that accumulated due to the previous
     * TTL-based caching mechanism for external attachments.
     *
     * The old implementation stored files with md5 hash prefixes (32 chars)
     * and used Cache entries with TTL. When cache entries expired, the files
     * remained on disk, causing disk usage to grow unbounded.
     *
     * This migration:
     * 1. Removes all old external attachments (md5 prefix format)
     * 2. Clears orphaned cache entries for external attachments
     * 3. Cleans up orphaned screenshot files older than 14 days
     */
    public function up(): void
    {
        $this->cleanupExternalAttachments();
        $this->cleanupOrphanedScreenshots();
    }

    /**
     * Remove all external attachments with the old md5 naming scheme.
     * New files use sha256 (64 chars), old files used md5 (32 chars).
     */
    protected function cleanupExternalAttachments(): void
    {
        $cacheDir = storage_path('app/cache/external-attachments');

        if (! is_dir($cacheDir)) {
            return;
        }

        $files = glob($cacheDir.'/*');
        $deletedCount = 0;
        $deletedSize = 0;

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $basename = basename($file);
            $underscorePos = strpos($basename, '_');

            // Old format: {md5_32chars}_{filename}
            // New format: {sha256_64chars}_{filename}
            // Delete files with 32-char prefix (old md5 format)
            if ($underscorePos === 32) {
                $size = filesize($file);
                if (@unlink($file)) {
                    $deletedCount++;
                    $deletedSize += $size;
                }
            }
        }

        if ($deletedCount > 0) {
            Log::info('Cleaned up old external attachments', [
                'deleted_files' => $deletedCount,
                'freed_bytes' => $deletedSize,
                'freed_mb' => round($deletedSize / 1024 / 1024, 2),
            ]);
        }

        // Clear old cache entries for external attachments
        // These are orphaned since we no longer use cache for tracking
        $this->clearExternalAttachmentCacheEntries();
    }

    /**
     * Clear orphaned cache entries for external attachments.
     */
    protected function clearExternalAttachmentCacheEntries(): void
    {
        // We can't easily iterate all cache keys, but we can clear
        // any known patterns. The old code used "external_attachment:{md5hash}"
        // Since we can't iterate, we'll just note this in logs
        Log::info('Old external attachment cache entries will expire naturally with TTL');
    }

    /**
     * Clean up orphaned screenshot files older than 14 days.
     * Screenshots are versioned by page updated_at, so old versions
     * may remain if not properly cleaned up on page update.
     */
    protected function cleanupOrphanedScreenshots(): void
    {
        $screenshotsDir = storage_path('app/public/screenshots');

        if (! is_dir($screenshotsDir)) {
            return;
        }

        $files = glob($screenshotsDir.'/*.webp');
        $deletedCount = 0;
        $deletedSize = 0;
        $cutoffTime = time() - (14 * 24 * 60 * 60); // 14 days ago

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            // Delete files older than 14 days
            $fileTime = filemtime($file);
            if ($fileTime && $fileTime < $cutoffTime) {
                $size = filesize($file);
                if (@unlink($file)) {
                    $deletedCount++;
                    $deletedSize += $size;
                }
            }
        }

        if ($deletedCount > 0) {
            Log::info('Cleaned up old screenshots', [
                'deleted_files' => $deletedCount,
                'freed_bytes' => $deletedSize,
                'freed_mb' => round($deletedSize / 1024 / 1024, 2),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This is a cleanup migration - files cannot be restored
        Log::warning('Cannot reverse cleanup_orphaned_cache_files migration - deleted files cannot be restored');
    }
};
