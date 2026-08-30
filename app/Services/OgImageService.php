<?php

namespace App\Services;

use App\Models\Page;
use App\Support\ScreenshotConfig;
use App\Support\Seo;
use App\Support\SocialCard;
use App\Support\SocialCardContent;
use App\Support\TakumiRenderer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * The site's share cards: the image a link preview shows, and the image the
 * Telegram bot sends above a page's reply.
 *
 * Both used to be browser screenshots of the page itself — the whole app booted
 * and painted by Chromium so that 720 pixels of it could be photographed. They
 * are now drawn: two Blade templates under resources/views/social, laid out by
 * the Takumi engine ({@see TakumiRenderer}). No browser, no page load, no
 * network, and a card that is legible at thumbnail size instead of a shrunken
 * document page.
 *
 * They still carry the page, though. A screenshot's one real virtue was that it
 * showed what the page SAID, and a card of title-and-description was less than
 * the site had been sharing — so the body of each card is the page's own
 * content, rewritten into markup the engine can lay out by
 * {@see SocialCardContent}. The wide card shows as much as its fixed box holds
 * and fades the cut; the tall one grows with the page up to a ceiling and says
 * so when it stops short.
 *
 * A rendered card is written to disk and its path returned, because that is
 * what both callers want — one hands the file to `response()->file()`, the
 * other to Telegram — and because the file doubles as the cache. What keys it
 * is the card's own content ({@see SocialCard::fingerprint()}): two pages that
 * would produce the same image share the file, and an edit that changes what a
 * card says lands on a new one without anybody clearing a key. The 7-day TTL is
 * now only about reclaiming disk.
 *
 * Failures are the callers' to interpret and neither is changed by this being a
 * renderer rather than a browser: the OG controller catches, logs and answers
 * 500 (a preview is worth less than a page), while the bot lets the exception
 * out (a page reply with no image is a broken reply).
 */
class OgImageService
{
    public const TYPE_BOT = 'bot';

    public const TYPE_OG = 'og';

    /**
     * The two cards: the template that draws each, its size in CSS pixels, and
     * how much text it has room for.
     *
     * Every `limits` number is a line count in disguise: the frame is a fixed
     * box and the engine will happily paint a fourth line of a three-line title
     * outside it, so each field is trimmed to what fits in the lines the
     * template reserved for it.
     *
     * `content` is the different kind of budget — how much of the PAGE each
     * card draws ({@see SocialCardContent}). It is not a safety limit: both
     * templates clip their content box in CSS, so the numbers say how much is
     * worth drawing rather than how much is safe to. The wide card fades what
     * it cannot fit; the tall one says «تابع القراءة في الموقع».
     */
    private const CARDS = [
        self::TYPE_OG => [
            'view' => 'social.og-card',
            'width' => 720,
            'height' => 378,
            'minHeight' => null,
            'limits' => ['title' => 72, 'description' => 118, 'section' => 26, 'url' => 52],
            // No images: this card is drawn inside a crawler's request, and an
            // image budget is what would let it wait on somebody else's server.
            // Its four visible lines are no place for a picture anyway.
            'content' => ['characters' => 340, 'images' => 0, 'width' => 636],
        ],
        self::TYPE_BOT => [
            'view' => 'social.bot-card',
            'width' => 720,
            // Height follows the content, exactly as the quiz card's does: a
            // page reply is READ in the group, and a fixed box would either
            // crop a long page or pad a short one.
            'height' => null,
            'minHeight' => 720,
            'limits' => ['title' => 80, 'description' => 200, 'section' => 30, 'url' => 46],
            'content' => ['characters' => 2200, 'images' => 4, 'width' => 632],
        ],
    ];

    /**
     * The cards are drawn at twice their CSS size: 1440 × 756 and 1440 × 1440.
     *
     * Not a retina flourish — 720 pixels was under every platform's recommended
     * width for a large summary card, so the old screenshots were being
     * upscaled by the very previews they were made for. resources/views/app.blade.php
     * publishes the doubled numbers in og:image:width/height; change them together.
     */
    private const SCALE = 2.0;

    /**
     * Bumped when the templates change, because nothing else about a card's
     * appearance is in its fingerprint. Without it a redesign would reach
     * visitors only as the week-old files expired, page by page.
     */
    private const DESIGN_VERSION = '2';

    /**
     * Titles for the handful of routes that are pages in the navigation but may
     * have no `pages` row behind them — the tools and directories whose content
     * lives in a Vue component.
     *
     * These mirror the fallback titles their controllers pass to
     * {@see Seo::forDefault()} and are consulted only when the lookup finds no
     * page, which on a fully-populated site is never. A tool without a page row
     * still deserves its own name on a shared link rather than the site's.
     */
    private const ROUTE_TITLES = [
        'adwat/almkafa' => 'موعد المكافأة',
        'adwat/hasbh-alhrman' => 'حاسبة الحرمان',
        'adwat/hasbh-almadl' => 'حاسبة المعدل',
        'adwat/hasbh-altahwel' => 'حاسبة التحويل',
        'adwat/jdwal-alsawab' => 'جداول الصواب',
        'adwat/sorh-albtaqa' => 'صورة البطاقة الجامعية',
        'adwat/alkhosousieen' => 'المدرسون الخصوصيون',
        'qroubat' => 'قروبات الطلاب',
        'almosaed' => 'المساعد الذكي',
    ];

    /** The site mark, read once per process from the favicon it shares. */
    private static ?string $logo = null;

    public function __construct(
        private readonly TakumiRenderer $takumi = new TakumiRenderer,
        private readonly SocialCardContent $content = new SocialCardContent,
    ) {}

    /**
     * The card for a page, as a path to a PNG on disk.
     *
     * Old renders of the same page are swept before a new one is written: the
     * filename carries the card's fingerprint, so an edited page leaves its
     * previous card behind and they would otherwise pile up one per edit.
     */
    public function generatePageScreenshot(Page $page, string $type = self::TYPE_BOT): string
    {
        $card = SocialCard::forPage($page, $this->host());
        $slug = $this->normalizeSlug($page->slug);

        if ($this->isCached($type, $slug, $card)) {
            return $this->pathFor($type, $slug, $card);
        }

        $this->clearOldScreenshots($page->slug);

        return $this->render($card, $type, $slug);
    }

    /**
     * Whether a page's card is already on disk.
     *
     * The bot asks before it renders so it can say "one moment" only when there
     * is actually a wait — which there now rarely is, a card being about a
     * second of layout rather than a browser start-up.
     */
    public function hasPageScreenshot(Page $page, string $type = self::TYPE_BOT): bool
    {
        $card = SocialCard::forPage($page, $this->host());

        return $this->isCached($type, $this->normalizeSlug($page->slug), $card);
    }

    /**
     * The card for a route, as a path to a PNG on disk.
     *
     * The route is resolved to a page for its title, section and description;
     * a route with nothing behind it — an unknown URL, or a tool whose page has
     * not been written — gets the site's own card rather than an error, because
     * this is the endpoint a crawler hits and a preview is not a place to fail.
     */
    public function generateRouteScreenshot(string $route, string $type = self::TYPE_OG): string
    {
        $card = $this->cardForRoute($route);
        $slug = $this->normalizeSlug($route);

        if ($this->isCached($type, $slug, $card)) {
            return $this->pathFor($type, $slug, $card);
        }

        return $this->render($card, $type, $slug);
    }

    /**
     * The cache key for a page's card.
     *
     * Public because the Page model clears it on save. That is now belt and
     * braces rather than the mechanism — a card whose content changed has a
     * different key already — but it is what deletes the superseded file.
     */
    public function getPageCacheKey(Page $page, string $type = self::TYPE_BOT): string
    {
        return $this->cacheKey($type, $this->identifier($this->normalizeSlug($page->slug), SocialCard::forPage($page, $this->host())));
    }

    /**
     * Forget both of a page's cards.
     */
    public function clearPageCache(Page $page): void
    {
        $card = SocialCard::forPage($page, $this->host());
        $slug = $this->normalizeSlug($page->slug);

        foreach ([self::TYPE_BOT, self::TYPE_OG] as $type) {
            Cache::forget($this->cacheKey($type, $this->identifier($slug, $card)));

            $path = $this->pathFor($type, $slug, $card);

            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Delete every card file ever rendered for a slug, whatever it said at the
     * time. The fingerprint in the filename means a page that has been edited
     * has more than one.
     */
    public function clearOldScreenshots(string $slug): void
    {
        $directory = ScreenshotConfig::directory();

        if (! is_dir($directory)) {
            return;
        }

        $pattern = $directory.'/*_'.$this->normalizeSlug($slug).'_*.'.ScreenshotConfig::extension();

        foreach (glob($pattern) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Draw the card and write it where the caller can hand it on.
     *
     * @throws RuntimeException when the render fails; {@see TakumiRenderer} has
     *                          already logged why.
     */
    private function render(SocialCard $card, string $type, string $slug): string
    {
        $spec = self::CARDS[$type] ?? self::CARDS[self::TYPE_OG];

        $body = $this->content->build(
            $card->content,
            $spec['content']['characters'],
            $spec['content']['images'],
            $spec['content']['width'],
        );

        $html = View::make($spec['view'], [
            ...$card->trimmed($spec['limits']),
            'body' => $body->html,
            'truncated' => $body->truncated,
            'logo' => $this->logo(),
            'siteName' => Seo::siteName(),
        ])->render();

        $png = $this->takumi->render($html, $spec['width'], $spec['height'], $spec['minHeight'], self::SCALE);

        $path = $this->pathFor($type, $slug, $card);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Written beside its destination and moved into place, so a reader that
        // arrives mid-render never opens a half-written PNG: rename is atomic
        // within a filesystem, file_put_contents is not.
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($temporary, $png) === false || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Failed to write the rendered card to {$path}");
        }

        Cache::put($this->cacheKey($type, $this->identifier($slug, $card)), $path, config('app-cache.screenshots.ttl'));

        return $path;
    }

    /**
     * The card a route asks for: its page's, or the site's own.
     *
     * The visibility rule is the one the page controller serves under, so a
     * card exists for exactly the URLs that resolve — including the pages that
     * are hidden from navigation but reachable from an AI citation.
     */
    private function cardForRoute(string $route): SocialCard
    {
        $path = '/'.trim($route, '/');

        $page = Page::query()
            ->where('slug', $path)
            ->where(fn ($query) => $query->where('hidden', false)->orWhere('hidden_from_ai', false))
            ->with('parent')
            ->first();

        if ($page) {
            return SocialCard::forPage($page, $this->host());
        }

        return SocialCard::forSite(
            $this->host(),
            $path,
            self::ROUTE_TITLES[trim($route, '/')] ?? null,
        );
    }

    /**
     * Whether this exact card is already rendered and still cached.
     */
    private function isCached(string $type, string $slug, SocialCard $card): bool
    {
        return file_exists($this->pathFor($type, $slug, $card))
            && Cache::has($this->cacheKey($type, $this->identifier($slug, $card)));
    }

    private function pathFor(string $type, string $slug, SocialCard $card): string
    {
        return ScreenshotConfig::directory()
            ."/{$type}_".$this->identifier($slug, $card).'.'.ScreenshotConfig::extension();
    }

    /**
     * What distinguishes one card file from another: which page, and what it
     * said. The same string appears in the filename after the type and in the
     * cache key after the type, which is the shape `storage:cleanup
     * --screenshots` reads a filename back into a key with.
     */
    private function identifier(string $slug, SocialCard $card): string
    {
        return $slug.'_'.$card->fingerprint(self::DESIGN_VERSION);
    }

    private function cacheKey(string $type, string $identifier): string
    {
        return config('app-cache.keys.screenshot').":{$type}:{$identifier}";
    }

    /**
     * A slug as it appears in a filename: no slashes, and never empty.
     */
    private function normalizeSlug(string $slug): string
    {
        return str_replace('/', '_', trim($slug, '/')) ?: 'home';
    }

    /**
     * The host the card should print.
     *
     * The request's own, when there is one, for the same reason the screenshots
     * used it: APP_URL is not always the public address, and a card that names
     * the wrong host is worse than one that names none.
     */
    private function host(): string
    {
        if (! app()->runningInConsole() && request()->getHttpHost() !== '') {
            return request()->getHost();
        }

        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'uqucc.sb.sa';
    }

    /**
     * The site mark as a data: URI, taken from the favicon so the cards and the
     * browser tab can never drift apart, and recoloured white for the tinted
     * badge it sits in.
     *
     * It has to be an image rather than inline markup: the engine has no
     * <svg> parser.
     */
    private function logo(): string
    {
        if (self::$logo !== null) {
            return self::$logo;
        }

        $svg = @file_get_contents(public_path('favicon.svg'));

        if ($svg === false) {
            throw new RuntimeException('The site mark is missing: public/favicon.svg could not be read.');
        }

        return self::$logo = 'data:image/svg+xml;base64,'.base64_encode(
            str_ireplace('#298287', '#ffffff', $svg)
        );
    }
}
