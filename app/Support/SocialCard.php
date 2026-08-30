<?php

namespace App\Support;

use App\Models\Page;
use Illuminate\Support\Str;

/**
 * What a share card says.
 *
 * The site's link previews and the bot's page replies are drawn rather than
 * screenshotted ({@see \App\Services\OgImageService}), so a card is no longer
 * "whatever the page looked like" — it is these four fields and nothing else.
 * Keeping them in one object gives the two Blade templates a contract to draw
 * against, and gives the cache something small and stable to fingerprint: two
 * requests that would say the same thing share a rendered file, and an edit
 * that changes what a card says invalidates it without anyone clearing a key.
 *
 * The strings arrive un-truncated. Each template has a different amount of room
 * and trims to its own budget at render time, so the fingerprint stays a
 * property of the content rather than of the layout.
 */
final readonly class SocialCard
{
    /**
     * @param  string  $title  The headline — the page title, or the site name for a route with no page behind it.
     * @param  string|null  $section  The parent page's title, drawn as a pill above the headline; null when there is no parent.
     * @param  string  $description  One or two sentences under the headline.
     * @param  string  $url  Host and path, no scheme — the line in the card's footer.
     * @param  array<string, mixed>|string|null  $content  The page's own content, drawn into the card's body by {@see SocialCardContent}. Null for a route with no page behind it.
     */
    public function __construct(
        public string $title,
        public ?string $section,
        public string $description,
        public string $url,
        public array|string|null $content = null,
    ) {}

    /**
     * The card for a content page: its own title, its parent as the section,
     * and the same description the page's meta tags carry, so a preview and a
     * search result say the same thing.
     */
    public static function forPage(Page $page, string $host): self
    {
        $isHome = $page->slug === '/';

        return new self(
            title: $isHome ? Seo::siteName() : $page->title,
            section: $isHome ? null : $page->parent?->title,
            description: Seo::descriptionFor($page),
            url: self::url($host, $page->slug),
            content: $page->html_content,
        );
    }

    /**
     * The card for a route with no page record behind it — an unindexed URL, or
     * a tool page whose content has not been written yet. It carries the site's
     * own identity rather than an apology: a preview that says what the site is
     * still earns the click, and this is the only card a 404 can produce.
     */
    public static function forSite(string $host, string $path = '/', ?string $title = null): self
    {
        return new self(
            title: $title ?? Seo::siteName(),
            section: $title === null ? null : Seo::siteName(),
            description: Seo::DEFAULT_DESCRIPTION,
            url: self::url($host, $path),
        );
    }

    /**
     * The fields as the templates receive them, trimmed to what the given
     * layout can hold.
     *
     * The budgets are characters rather than lines because the engine gives no
     * way back from an overflow: `text-overflow: ellipsis` draws the ellipsis
     * and drops the text, and a card is a fixed box, so anything past the last
     * line would simply be painted outside it. Each limit is therefore set from
     * the layout's worst case — the tallest the field is allowed to grow — and
     * the templates leave room for exactly that.
     *
     * Whole words, because a headline cut mid-word reads as a bug rather than
     * as an abbreviation. The URL is the exception: it has no words, and its
     * tail is the least of what it says.
     *
     * @param  array{title: int, section: int, description: int, url: int}  $limits  Characters per field, from OgImageService::CARDS.
     * @return array{title: string, section: string|null, description: string, url: string}
     */
    public function trimmed(array $limits): array
    {
        return [
            'title' => Str::limit($this->title, $limits['title'], '…', preserveWords: true),
            'section' => $this->section === null ? null : Str::limit($this->section, $limits['section'], '…', preserveWords: true),
            'description' => Str::limit($this->description, $limits['description'], '…', preserveWords: true),
            'url' => Str::limit($this->url, $limits['url'], '…'),
        ];
    }

    /**
     * A short, stable digest of everything the card says — the identity of the
     * rendered file, and the reason a cache key never has to be cleared by hand.
     *
     * xxh128 rather than md5: it is not a security boundary (nothing here is
     * secret and nobody can choose the input), it is a filename, and this is
     * both faster and shorter.
     *
     * @param  string  ...$extra  Anything outside the card's own words that
     *                            changes the image — the design's version.
     */
    public function fingerprint(string ...$extra): string
    {
        return hash('xxh128', implode("\x1f", [
            $this->title,
            $this->section ?? '',
            $this->description,
            $this->url,
            $this->contentDigest(),
            ...$extra,
        ]));
    }

    /**
     * A digest of the page content the body is drawn from — the RAW content,
     * not the transformed markup.
     *
     * Deliberately the input rather than the output, because this is computed
     * on paths that must not do I/O: the bot asks whether a card is ready
     * before it renders one, and a page save clears its keys. Transforming the
     * body there would read the media disk and reach the network for every
     * embedded image just to name a file.
     *
     * It loses nothing. The transform is deterministic, so identical content
     * yields identical markup, and OgImageService::DESIGN_VERSION covers the
     * transform itself changing. Embedded images are covered by their src:
     * uploads are stored under a per-upload hash name, so replacing a page's
     * image changes the src, and an external src is the identity of the image
     * it points at. The digest is conservative in the other direction — an edit
     * that does not reach the visible body still redraws the card — which costs
     * one render.
     */
    private function contentDigest(): string
    {
        if ($this->content === null) {
            return '';
        }

        return hash('xxh128', is_string($this->content)
            ? $this->content
            : (string) json_encode($this->content, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    private static function url(string $host, string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return rtrim($host, '/').($path === '/' ? '' : rtrim($path, '/'));
    }
}
