<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Page;
use Illuminate\Support\Facades\Log;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use Symfony\Component\Yaml\Yaml;

class MarkdownMigrationService
{
    private CommonMarkConverter $converter;

    private string $contentPath;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new FrontMatterExtension);

        $this->converter = new CommonMarkConverter([], $environment);
        $this->contentPath = '/home/saad/uqucc/content';
    }

    /**
     * Migrate all markdown files to database
     */
    public function migrate(?string $specificPath = null, bool $dryRun = false): array
    {
        $files = $this->getMarkdownFiles($specificPath);

        $success = 0;
        $errors = 0;
        $results = [];

        foreach ($files as $file) {
            try {
                $result = $this->migrateFile($file, $dryRun);
                $success++;
                $results[] = $result;
                echo "✓ Migrated: {$result['slug']}\n";
            } catch (\Exception $e) {
                $errors++;
                Log::error("Failed to migrate {$file}: {$e->getMessage()}");
                echo "✗ Failed: {$file} - {$e->getMessage()}\n";
            }
        }

        return compact('success', 'errors', 'results');
    }

    /**
     * Migrate a single markdown file
     */
    private function migrateFile(string $filePath, bool $dryRun): array
    {
        $content = file_get_contents($filePath);

        // Parse frontmatter and markdown content
        $parsed = $this->parseFrontmatter($content);
        $frontmatter = $parsed['frontmatter'];
        $markdown = $parsed['markdown'];

        // Convert markdown to HTML
        $html = $this->convertMarkdownToHtml($markdown);

        // Convert MDC components to HTML component tags
        $html = $this->convertMdcToHtml($html);

        // Generate slug from file path
        $slug = $this->generateSlug($filePath);

        // Get parent slug for hierarchy
        $parentSlug = $this->getParentSlug($slug);

        // Calculate level (depth in hierarchy)
        $level = substr_count($slug, '/') - 1; // -1 because slug starts with /

        $pageData = [
            'slug' => $slug,
            'title' => $frontmatter['title'] ?? $this->extractTitle($html),
            'html_content' => $html,
            'order' => $frontmatter['order'] ?? 0,
            'icon' => $frontmatter['icon'] ?? null,
            'hidden' => $frontmatter['hidden'] ?? false,
            'level' => $level,
            'extension' => pathinfo($filePath, PATHINFO_EXTENSION),
            'quick_response_enabled' => $frontmatter['quickResponseEnabled'] ?? false,
            'quick_response_send_link' => $frontmatter['quickResponseSendLink'] ?? true,
            'quick_response_message' => $frontmatter['quickResponseMessage'] ?? null,
            'quick_response_button_label' => $frontmatter['quickResponseButtonLabel'] ?? null,
            'quick_response_button_url' => $frontmatter['quickResponseButtonUrl'] ?? null,
            'quick_response_attachments' => $frontmatter['quickResponseAttachments'] ?? [],
        ];

        if (! $dryRun) {
            // Create or update page
            $page = Page::updateOrCreate(
                ['slug' => $slug],
                $pageData
            );

            // Set parent after all pages are created (will be done in second pass)
            if ($parentSlug) {
                $parent = Page::where('slug', $parentSlug)->first();
                if ($parent) {
                    $page->parent_id = $parent->id;
                    $page->save();
                }
            }

            // Attach authors
            if (isset($frontmatter['authors']) && is_array($frontmatter['authors'])) {
                $authorIds = Author::whereIn('username', $frontmatter['authors'])->pluck('id');
                $page->authors()->sync($authorIds);
            }

            $pageData['id'] = $page->id;
        }

        return $pageData;
    }

    /**
     * Parse YAML frontmatter from markdown content
     */
    private function parseFrontmatter(string $content): array
    {
        // Extract YAML frontmatter between --- markers
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
            try {
                return [
                    'frontmatter' => Yaml::parse($matches[1]) ?? [],
                    'markdown' => $matches[2],
                ];
            } catch (\Exception $e) {
                Log::warning("Failed to parse frontmatter: {$e->getMessage()}");

                return [
                    'frontmatter' => [],
                    'markdown' => $content,
                ];
            }
        }

        return [
            'frontmatter' => [],
            'markdown' => $content,
        ];
    }

    /**
     * Convert markdown to HTML using CommonMark
     */
    private function convertMarkdownToHtml(string $markdown): string
    {
        return $this->converter->convert($markdown)->getContent();
    }

    /**
     * Convert MDC component syntax to HTML component tags
     * Example: ::info becomes <component is="Info">
     */
    private function convertMdcToHtml(string $html): string
    {
        // Pattern: ::component-name (with optional attributes)
        // Content between :: markers
        $html = preg_replace_callback(
            '/::(\w+)(?:\s+([^\n]*))?\s*\n(.*?)\n::/s',
            function ($matches) {
                $component = $matches[1];
                $attributes = $matches[2] ?? '';
                $content = $matches[3] ?? '';

                // Convert kebab-case to PascalCase
                $componentName = str_replace(' ', '', ucwords(str_replace('-', ' ', $component)));

                return "<div class=\"mdc-{$component}\">{$content}</div>";
            },
            $html
        );

        return $html;
    }

    /**
     * Generate slug from file path
     */
    private function generateSlug(string $filePath): string
    {
        $relative = str_replace($this->contentPath.'/', '', $filePath);
        $relative = preg_replace('/\.(md|mdc)$/', '', $relative);

        // Handle index files
        if (basename($relative) === 'index') {
            $relative = dirname($relative);
            if ($relative === '.') {
                return '/';
            }
        }

        return '/'.trim($relative, '/');
    }

    /**
     * Get parent slug from a slug
     */
    private function getParentSlug(string $slug): ?string
    {
        if ($slug === '/') {
            return null;
        }

        $parts = explode('/', trim($slug, '/'));
        array_pop($parts);

        if (count($parts) === 0) {
            return '/';
        }

        return '/'.implode('/', $parts);
    }

    /**
     * Extract title from HTML h1 tag
     */
    private function extractTitle(string $html): string
    {
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $html, $matches)) {
            return strip_tags($matches[1]);
        }

        return 'Untitled';
    }

    /**
     * Get all markdown files
     */
    private function getMarkdownFiles(?string $specificPath = null): array
    {
        $path = $specificPath ? $this->contentPath.'/'.$specificPath : $this->contentPath;

        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['md', 'mdc'])) {
                // Skip .navigation.yml pattern files
                if (strpos($file->getFilename(), '.navigation') === false) {
                    $files[] = $file->getPathname();
                }
            }
        }

        // Sort files to ensure parents are created before children
        sort($files);

        return $files;
    }

    /**
     * Update parent relationships (second pass after all pages are created)
     */
    public function updateParentRelationships(): void
    {
        $pages = Page::all();

        foreach ($pages as $page) {
            $parentSlug = $this->getParentSlug($page->slug);

            if ($parentSlug) {
                $parent = Page::where('slug', $parentSlug)->first();
                if ($parent && $page->parent_id !== $parent->id) {
                    $page->parent_id = $parent->id;
                    $page->save();
                }
            }
        }

        echo "✓ Updated parent relationships\n";
    }
}
