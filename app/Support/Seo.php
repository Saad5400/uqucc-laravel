<?php

namespace App\Support;

use App\Models\Page;
use Illuminate\Support\Str;

/**
 * Builds per-page SEO metadata (title, description, canonical, Open Graph,
 * Twitter card and JSON-LD structured data) that is shared with the Inertia
 * root template and rendered server-side for crawlers.
 *
 * @phpstan-type SeoArray array{
 *     title: string,
 *     fullTitle: string,
 *     description: string,
 *     canonical: string,
 *     ogType: string,
 *     schema: array<int, array<string, mixed>>
 * }
 */
class Seo
{
    public const DEFAULT_DESCRIPTION = 'دليلك الشامل لكل ما يخص كلية الحاسبات، من تخصصات، نصائح، وأدوات لمساعدتك في رحلتك الأكاديمية.';

    /**
     * Build SEO metadata for a content/tool page.
     *
     * @param  array<int, array{title: string, path: string}>  $breadcrumbs
     * @return SeoArray
     */
    public static function forPage(Page $page, array $breadcrumbs = [], string $ogType = 'article'): array
    {
        $siteName = self::siteName();
        $isHome = $page->slug === '/';

        $title = $isHome ? $siteName : $page->title;
        $description = self::descriptionFor($page);
        $canonical = url()->current();

        $schema = [
            self::websiteSchema(),
            self::organizationSchema(),
        ];

        if (! empty($breadcrumbs)) {
            $schema[] = self::breadcrumbSchema($breadcrumbs);
        }

        if (! $isHome) {
            $schema[] = self::articleSchema($page, $title, $description, $canonical);
        }

        return [
            'title' => $title,
            'fullTitle' => $isHome ? $siteName : "{$title} - {$siteName}",
            'description' => $description,
            'canonical' => $canonical,
            'ogType' => $isHome ? 'website' : $ogType,
            'schema' => $schema,
        ];
    }

    /**
     * Build SEO metadata for a route that has no backing page record.
     *
     * @return SeoArray
     */
    public static function forDefault(string $title, ?string $description = null): array
    {
        $siteName = self::siteName();
        $canonical = url()->current();

        return [
            'title' => $title,
            'fullTitle' => "{$title} - {$siteName}",
            'description' => $description ?: self::DEFAULT_DESCRIPTION,
            'canonical' => $canonical,
            'ogType' => 'website',
            'schema' => [
                self::websiteSchema(),
                self::organizationSchema(),
            ],
        ];
    }

    public static function siteName(): string
    {
        return config('app.name', 'دليل طالب كلية الحاسبات');
    }

    /**
     * Derive a meaningful, unique description for a page.
     */
    public static function descriptionFor(Page $page): string
    {
        if ($page->slug === '/') {
            return self::DEFAULT_DESCRIPTION;
        }

        $candidates = [
            $page->quick_response_message,
            $page->html_content,
        ];

        foreach ($candidates as $candidate) {
            $text = self::normalize(self::plainTextFromContent($candidate));

            if ($text !== '') {
                return Str::limit($text, 157);
            }
        }

        return self::DEFAULT_DESCRIPTION;
    }

    /**
     * Extract readable plain text from the page's html_content.
     *
     * The attribute may be a TipTap-style block array or a raw HTML string.
     */
    private static function plainTextFromContent(mixed $content): string
    {
        if (blank($content)) {
            return '';
        }

        if (is_array($content)) {
            return self::extractTextFromBlocks($content);
        }

        return html_entity_decode(strip_tags((string) $content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Recursively collect "text" nodes from a TipTap-style document tree.
     *
     * @param  array<mixed>  $nodes
     */
    private static function extractTextFromBlocks(array $nodes): string
    {
        $text = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (isset($node['text']) && is_string($node['text'])) {
                $text .= ' '.$node['text'];
            }

            foreach (['content', 'children'] as $childKey) {
                if (isset($node[$childKey]) && is_array($node[$childKey])) {
                    $text .= ' '.self::extractTextFromBlocks($node[$childKey]);
                }
            }
        }

        return $text;
    }

    private static function normalize(string $value): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $value));
    }

    /**
     * @return array<string, mixed>
     */
    private static function websiteSchema(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => self::siteName(),
            'url' => url('/'),
            'inLanguage' => 'ar',
            'description' => self::DEFAULT_DESCRIPTION,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function organizationSchema(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => self::siteName(),
            'url' => url('/'),
            'logo' => url('/apple-touch-icon.png'),
        ];
    }

    /**
     * @param  array<int, array{title: string, path: string}>  $breadcrumbs
     * @return array<string, mixed>
     */
    private static function breadcrumbSchema(array $breadcrumbs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($breadcrumbs)->values()->map(fn (array $crumb, int $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['title'],
                'item' => url($crumb['path']),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function articleSchema(Page $page, string $title, string $description, string $canonical): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'TechArticle',
            'headline' => $title,
            'description' => $description,
            'inLanguage' => 'ar',
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $canonical,
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => self::siteName(),
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => url('/apple-touch-icon.png'),
                ],
            ],
        ];

        if ($page->created_at) {
            $schema['datePublished'] = $page->created_at->toIso8601String();
        }

        if ($page->updated_at) {
            $schema['dateModified'] = $page->updated_at->toIso8601String();
        }

        if ($page->relationLoaded('users') && $page->users->isNotEmpty()) {
            $schema['author'] = $page->users->map(fn ($user) => array_filter([
                '@type' => 'Person',
                'name' => $user->name,
                'url' => $user->url,
            ]))->values()->all();
        }

        return $schema;
    }
}
