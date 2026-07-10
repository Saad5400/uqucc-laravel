<?php

namespace App\Console\Commands;

use App\Models\Ai\PageContentProposal;
use App\Models\Page;
use App\Support\Disk;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * One-time (but idempotent) production migration of legacy local files to
 * object storage: copies every file from the legacy local `public` disk to
 * the env-resolved media disk, then rewrites every DB-stored `/storage/...`
 * reference to the media disk's public URL — via Eloquent, so the cache
 * flushing model events keep firing.
 *
 * The URL rewrite is the real correctness mechanism: the production
 * container's filesystem is ephemeral, so old `/storage/...` URLs die on the
 * next redeploy regardless. Rewritten references: TipTap `image` node srcs
 * (nested anywhere, e.g. inside paragraphs), link mark hrefs, `<img>`/`<a>`
 * URLs inside customBlock attr HTML strings (rewritten inside the decoded
 * JSON structure, never over serialized JSON), legacy HTML-string pages,
 * quick-response attachments/buttons/messages, and pending AI content
 * proposals.
 */
class MigrateStorageToS3 extends Command
{
    protected $signature = 'storage:migrate-to-s3
                            {--dry-run : Report what would be copied and rewritten without changing anything}
                            {--from=public : Source disk holding the legacy files}
                            {--to=media : Target disk (the env-resolved media disk)}';

    protected $description = 'Copy legacy public-disk files to the S3-backed media disk and rewrite stored /storage/... URLs';

    /** URL path prefix the legacy public disk served files under. */
    private const STORAGE_URL_PREFIX = '/storage/';

    /** Source-disk directories that are regenerable caches — never migrated. */
    private const CACHE_DIRECTORIES = ['screenshots/', 'external-attachments/'];

    private Filesystem $from;

    private Filesystem $to;

    private bool $dryRun = false;

    /** @var list<string> Referenced storage URLs whose file exists on neither disk. */
    private array $unresolved = [];

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->from = Storage::disk((string) $this->option('from'));
        $this->to = Storage::disk((string) $this->option('to'));

        if ($this->dryRun) {
            $this->warn('DRY RUN — nothing will be copied or rewritten.');
        }

        $this->copyFiles();
        $this->rewritePages();
        $this->rewriteProposals();

        foreach (array_unique($this->unresolved) as $url) {
            $this->warn("Unresolved reference (file missing on both disks, left untouched): {$url}");
        }

        return self::SUCCESS;
    }

    /**
     * Copy every non-cache file from the source disk to the target disk,
     * skipping files that already exist there with the same size.
     */
    private function copyFiles(): void
    {
        $copied = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->from->allFiles() as $path) {
            if ($this->isCachePath($path)) {
                continue;
            }

            if ($this->to->exists($path) && $this->to->size($path) === $this->from->size($path)) {
                $skipped++;

                continue;
            }

            if ($this->dryRun) {
                $this->line("Would copy: {$path}");
                $copied++;

                continue;
            }

            $stream = $this->from->readStream($path);

            if (! is_resource($stream) || $this->to->writeStream($path, $stream) === false) {
                $this->error("Failed to copy: {$path}");
                $failed++;

                continue;
            }

            if (is_resource($stream)) {
                fclose($stream);
            }

            $copied++;
        }

        $this->info(sprintf(
            'Files: %d %s, %d already up to date, %d failed.',
            $copied,
            $this->dryRun ? 'would be copied' : 'copied',
            $skipped,
            $failed,
        ));
    }

    /**
     * Rewrite storage URLs in every page (trashed included, so restored
     * pages come back with working images) through Eloquent updates.
     */
    private function rewritePages(): void
    {
        $updated = 0;

        foreach (Page::withTrashed()->cursor() as $page) {
            $dirty = [];

            $content = $page->html_content;
            $rewritten = is_array($content)
                ? $this->rewriteValue($content)
                : (is_string($content) ? $this->rewriteString($content) : $content);

            if ($rewritten !== $content) {
                $dirty['html_content'] = $rewritten;
            }

            $attachments = $page->quick_response_attachments;

            if (is_array($attachments)) {
                $newAttachments = array_map(fn ($value) => is_string($value) ? $this->normalizeAttachment($value) : $value, $attachments);

                if ($newAttachments !== $attachments) {
                    $dirty['quick_response_attachments'] = $newAttachments;
                }
            }

            $buttons = $page->quick_response_buttons;

            if (is_array($buttons)) {
                $newButtons = $this->rewriteValue($buttons);

                if ($newButtons !== $buttons) {
                    $dirty['quick_response_buttons'] = $newButtons;
                }
            }

            $message = $page->quick_response_message;

            if (is_string($message)) {
                $newMessage = $this->rewriteString($message);

                if ($newMessage !== $message) {
                    $dirty['quick_response_message'] = $newMessage;
                }
            }

            if ($dirty === []) {
                continue;
            }

            $updated++;

            if ($this->dryRun) {
                $this->line(sprintf('Would rewrite page #%d (%s): %s', $page->id, $page->slug, implode(', ', array_keys($dirty))));

                continue;
            }

            $page->update($dirty);
        }

        $this->info(sprintf('Pages: %d %s.', $updated, $this->dryRun ? 'would be rewritten' : 'rewritten'));
    }

    /**
     * Pending AI content proposals hold full TipTap documents too — rewrite
     * them so applying a proposal later never reinstates dead URLs.
     */
    private function rewriteProposals(): void
    {
        $updated = 0;

        foreach (PageContentProposal::query()->cursor() as $proposal) {
            $content = $proposal->proposed_html_content;

            if (! is_array($content)) {
                continue;
            }

            $rewritten = $this->rewriteValue($content);

            if ($rewritten === $content) {
                continue;
            }

            $updated++;

            if ($this->dryRun) {
                $this->line("Would rewrite content proposal #{$proposal->id}");

                continue;
            }

            $proposal->update(['proposed_html_content' => $rewritten]);
        }

        $this->info(sprintf('Content proposals: %d %s.', $updated, $this->dryRun ? 'would be rewritten' : 'rewritten'));
    }

    /**
     * Recursively rewrite storage URLs inside a decoded TipTap structure (or
     * any nested array). Every string leaf is rewritten EXCEPT `text` node
     * values — attrs (image src, customBlock config HTML), mark attrs (link
     * href), and nested arrays are all covered without regexing serialized
     * JSON.
     */
    private function rewriteValue(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $childKey => $child) {
                $result[$childKey] = $this->rewriteValue($child, is_string($childKey) ? $childKey : $key);
            }

            return $result;
        }

        if (is_string($value) && $key !== 'text') {
            return $this->rewriteString($value);
        }

        return $value;
    }

    /**
     * Replace every mappable storage URL inside a string (an HTML fragment,
     * a bare URL attr value, or a plain-text message). Absolute URLs on any
     * host qualify only when their path starts with /storage/, so external
     * links are never touched.
     */
    private function rewriteString(string $value): string
    {
        if (! str_contains($value, self::STORAGE_URL_PREFIX)) {
            return $value;
        }

        // A value that IS one URL (image src, link href, button url) is
        // mapped as a whole — this also covers filenames containing spaces,
        // which a token-based scan cannot delimit.
        $whole = $this->mediaUrlFor(trim($value));

        if ($whole !== null) {
            return $whole;
        }

        return (string) preg_replace_callback(
            '~https?://[^\s"\'<>()]+|(?<![\w.:/-])/storage/[^\s"\'<>()]+~u',
            fn (array $matches): string => $this->mediaUrlFor($matches[0]) ?? $matches[0],
            $value,
        );
    }

    /**
     * The media-disk public URL replacing one legacy storage URL, or null
     * when the URL is not ours / its file cannot be found on either disk.
     */
    private function mediaUrlFor(string $url): ?string
    {
        $relative = $this->storagePathFor($url);

        if ($relative === null) {
            return null;
        }

        return $this->to->url($relative);
    }

    /**
     * The disk-relative path behind a legacy /storage/... URL (host-relative
     * or absolute), verified to exist on the source or target disk; null for
     * anything else. Registers unmappable references for the final report.
     */
    private function storagePathFor(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || ! str_starts_with($path, self::STORAGE_URL_PREFIX)) {
            return null;
        }

        $relative = substr($path, strlen(self::STORAGE_URL_PREFIX));

        if ($relative === '' || str_contains($relative, '..') || $this->isCachePath($relative)) {
            return null;
        }

        foreach (array_unique([rawurldecode($relative), $relative]) as $candidate) {
            if ($this->from->exists($candidate) || $this->to->exists($candidate)) {
                return $candidate;
            }
        }

        $this->unresolved[] = $url;

        return null;
    }

    /**
     * Quick-response attachment entries store disk-relative paths; entries
     * that hold a legacy storage URL instead are normalized back to the
     * relative path (which every consumer resolves on the media disk).
     */
    private function normalizeAttachment(string $value): string
    {
        return $this->storagePathFor($value) ?? $value;
    }

    private function isCachePath(string $path): bool
    {
        foreach (self::CACHE_DIRECTORIES as $directory) {
            if (str_starts_with($path, $directory)) {
                return true;
            }
        }

        return false;
    }
}
