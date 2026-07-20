<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Services\NavigationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Dev-only tool: mirror the live site's public pages into the local DB so the
 * redesign can be judged against real content. Reads each page's Inertia
 * `data-page` payload — no HTML scraping. Local environment only.
 */
class ImportLiveContent extends Command
{
    protected $signature = 'dev:import-live-content {--base=https://uqucc.sb.sa}';

    protected $description = 'Import public page content from the live site into the local DB (local only)';

    private string $base;

    /** @var array<string, bool> */
    private array $visited = [];

    private int $imported = 0;

    /** @var array<int, string> */
    private array $failures = [];

    public function handle(): int
    {
        abort_unless(app()->environment('local'), 403, 'This command runs on local only.');

        $this->base = rtrim((string) $this->option('base'), '/');

        $this->info("Importing from {$this->base} …");
        $this->crawl('/', null, 0, 0);

        app(NavigationService::class)->clearCache();

        $this->newLine();
        $this->info("Imported {$this->imported} pages.");
        if ($this->failures !== []) {
            $this->warn('Failures ('.count($this->failures).'):');
            foreach ($this->failures as $failure) {
                $this->line("  - {$failure}");
            }
        }

        return self::SUCCESS;
    }

    private function crawl(string $slug, ?int $parentId, int $level, int $order): void
    {
        if (isset($this->visited[$slug]) || str_starts_with($slug, '/manage')) {
            return;
        }
        $this->visited[$slug] = true;

        $page = $this->fetchPage($slug);
        if ($page === null) {
            return;
        }

        $record = Page::updateOrCreate(
            ['slug' => $page['slug'] ?? $slug],
            [
                'title' => $page['title'] ?? $slug,
                'html_content' => $page['html_content'] ?? ['type' => 'doc', 'content' => []],
                'icon' => $page['icon'] ?? null,
                'order' => $order,
                'level' => $level,
                'parent_id' => $parentId,
                'hidden' => false,
                'extension' => 'md',
            ]
        );
        $this->imported++;
        $this->line("  ✓ {$record->slug}");

        $catalog = $page['catalog'] ?? [];
        $isRoot = ($page['slug'] ?? $slug) === '/';

        foreach ($catalog as $index => $child) {
            $childSlug = $child['slug'] ?? null;
            if (! is_string($childSlug) || $childSlug === '') {
                continue;
            }

            // Root's catalog is the top-level sections (parent_id null); every
            // other page's catalog is its real children.
            $this->crawl(
                $childSlug,
                $isRoot ? null : $record->id,
                $isRoot ? 0 : $level + 1,
                $index,
            );
        }
    }

    /**
     * Fetch a live page and return its Inertia `props.page` payload.
     *
     * @return array<string, mixed>|null
     */
    private function fetchPage(string $slug): ?array
    {
        $url = $this->base.($slug === '/' ? '/' : $slug);

        try {
            // Local dev crawl of a known site; Windows PHP ships no CA bundle.
            $response = Http::withoutVerifying()->timeout(20)->retry(2, 500)->get($url);
        } catch (\Throwable $e) {
            $this->failures[] = "{$slug}: {$e->getMessage()}";

            return null;
        }

        if (! $response->successful()) {
            $this->failures[] = "{$slug}: HTTP {$response->status()}";

            return null;
        }

        if (! preg_match('/<div id="app"[^>]*data-page="([^"]*)"/', $response->body(), $matches)) {
            $this->failures[] = "{$slug}: no data-page payload";

            return null;
        }

        $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);
        $page = $decoded['props']['page'] ?? null;

        if (! is_array($page)) {
            $this->failures[] = "{$slug}: unexpected payload shape";

            return null;
        }

        return $page;
    }
}
