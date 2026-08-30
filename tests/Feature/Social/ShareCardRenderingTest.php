<?php

use App\Models\Page;
use App\Services\OgImageService;
use App\Support\ScreenshotConfig;
use App\Support\TakumiRenderer;
use Database\Factories\PageFactory;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| The share cards, actually rendered
|--------------------------------------------------------------------------
|
| These tests run the real renderer: Blade, the Node entrypoint, the Takumi
| engine and the vendored fonts, end to end, and then look at the pixels that
| come out. Nothing here is stubbed except the one test that needs a failure,
| on purpose — a card is what a shared link and a bot reply look like, and every
| way an image pipeline breaks quietly is invisible to a test that only checks
| that some bytes came back: a missing font renders the layout with no text in
| it, a dropped stylesheet renders the text with no layout around it, and both
| are a well-formed PNG.
|
| They need `npm install` to have run. That is deliberate rather than skipped:
| @takumi-rs is a production dependency, and an environment without it cannot
| serve a preview.
|
*/

beforeEach(function () {
    // Cards are written to disk and the file IS the cache, so every test gets
    // its own directory: a leftover from the last run would otherwise be
    // indistinguishable from a cache hit.
    $this->cardsDirectory = sys_get_temp_dir().'/uqucc-share-cards-'.bin2hex(random_bytes(6));

    config(['screenshots.directory' => $this->cardsDirectory]);
});

afterEach(function () {
    foreach (glob($this->cardsDirectory.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->cardsDirectory);
});

/** The page's card, rendered for real, as a GD image. */
function renderShareCard(Page $page, string $type): GdImage
{
    $image = imagecreatefromstring(
        file_get_contents(app(OgImageService::class)->generatePageScreenshot($page, $type))
    );

    expect($image)->not->toBeFalse();

    return $image;
}

/**
 * How many of the card's pixels are within `$tolerance` of `$hex`, sampled on a
 * grid so the count is cheap and still representative.
 *
 * @param  string  $hex  Six hex digits, no leading #.
 */
function countCardPixelsNear(GdImage $image, string $hex, int $tolerance = 24): int
{
    [$wantRed, $wantGreen, $wantBlue] = sscanf($hex, '%2x%2x%2x');

    $found = 0;

    for ($x = 0; $x < imagesx($image); $x += 3) {
        for ($y = 0; $y < imagesy($image); $y += 3) {
            $color = imagecolorat($image, $x, $y);

            if (abs((($color >> 16) & 0xFF) - $wantRed) <= $tolerance
                && abs((($color >> 8) & 0xFF) - $wantGreen) <= $tolerance
                && abs(($color & 0xFF) - $wantBlue) <= $tolerance) {
                $found++;
            }
        }
    }

    return $found;
}

/**
 * How many horizontal runs of ink a slice of the card contains — one per
 * connected group of glyphs on a line of text.
 *
 * This is the one measurement that can tell shaped Arabic from tofu. A missing
 * Arabic face is not a blank card: Takumi falls back to the next registered
 * face, which carries no Arabic, and draws one hollow box per character — ink
 * in all the same places, passing every "did anything render" check. But boxes
 * never join and Arabic letters do, so the run count sits near the number of
 * words when the text is shaped and near the number of characters when it is
 * not.
 *
 * It is measured over a band rather than the whole card because the cards have
 * full-width chrome — the brand rule, the footer hairline — and a single
 * element spanning every column collapses the whole image to one run.
 *
 * @param  float  $top  Where the band starts, as a fraction of the card's height.
 * @param  float  $bottom  Where it ends.
 */
function countCardInkRuns(GdImage $image, float $top, float $bottom, int $threshold = 96): int
{
    $firstRow = (int) (imagesy($image) * $top);
    $lastRow = (int) (imagesy($image) * $bottom);

    $runs = 0;
    $previousHadInk = false;

    for ($x = 0; $x < imagesx($image); $x++) {
        $hasInk = false;

        for ($y = $firstRow; $y < $lastRow; $y++) {
            if ((imagecolorat($image, $x, $y) & 0xFF) > $threshold) {
                $hasInk = true;

                break;
            }
        }

        if ($hasInk && ! $previousHadInk) {
            $runs++;
        }

        $previousHadInk = $hasInk;
    }

    return $runs;
}

/** A one-paragraph TipTap document, for pages whose body is the point. */
function tipTapDocumentOf(string $text): array
{
    return [
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]],
    ];
}

/** A page whose title is the shaping probe: three words, fifteen letters. */
function probePage(): Page
{
    return PageFactory::new()->make([
        'slug' => '/altkhssat/aloom-alhasb',
        'title' => 'علوم الحاسب الآلي',
        'html_content' => 'تخصص علوم الحاسب الآلي يدرس البرمجة والخوارزميات وقواعد البيانات على مدى ثمانية مستويات دراسية.',
    ]);
}

it('shapes the page title with the vendored Arabic face rather than falling back to tofu', function (string $type, float $top, float $bottom) {
    // "علوم الحاسب الآلي" is fifteen letters that the face joins into eight
    // runs: ع-ل-و + م, ا + ل-ح-ا + س-ب, ا + ل-آ + ل-ي. Drawn as one box per
    // character it would be fifteen, and the wide card's brand rule adds one to
    // either count. Measured: 8 on both cards when the text is shaped, 16 and
    // 15 when it is not. The ceiling sits between the two rather than on
    // either, so it is structural rather than a metric that drifts with a font
    // update — but the BAND is not: it is where each layout currently puts its
    // headline, and it moves when the layout does. The two canaries above are
    // the layout-independent guards; this one is what proves the card itself
    // draws shaped Arabic.
    expect(countCardInkRuns(renderShareCard(probePage(), $type), $top, $bottom))
        ->toBeLessThanOrEqual(12);
})->with([
    'link preview card' => [OgImageService::TYPE_OG, 0.21, 0.32],
    'bot card' => [OgImageService::TYPE_BOT, 0.13, 0.22],
]);

it('has Arabic coverage at all, which is the canary that survives a change of fallback', function () {
    // Two different strings of the same length, drawn through the real script.
    // Tofu draws every uncovered character as the SAME box, so the two PNGs
    // come back byte-identical exactly when Arabic coverage is gone, and differ
    // whenever any registered face can draw it.
    //
    // This one asks nothing about WHICH face answers, which is what makes it
    // the portable check: the probe below can only tell the site face from the
    // fallback while the fallback happens to be a different, Arabic-covering
    // face, and that is a property of the font registry rather than of the
    // engine.
    $draw = fn (string $text): string => app(TakumiRenderer::class)->render(
        '<style>body{margin:0;background:#000;display:flex}</style>'
        ."<div style=\"width:400px;height:100px;display:flex;align-items:center;padding:0 16px;direction:rtl;font-family:'IBM Plex Sans Arabic';font-size:44px;color:#fff\">{$text}</div>",
        400,
        scale: 1.0,
    );

    expect($draw('بببببب'))->not->toBe($draw('تتتتتت'));
});

it('draws in the site face rather than the fallback the engine would reach for', function () {
    // The canary above catches a registry with no Arabic in it at all. This
    // catches the likelier accident: the SITE face dropping out of
    // scripts/takumi-render.mjs's registry while DejaVu Sans Mono stays. DejaVu
    // covers and joins Arabic, so the cards still come out shaped — in a
    // monospace face, a fifth wider, nothing like the site — and no coverage
    // check can see that.
    //
    // So ask the engine directly. Two renders of the same string in the two
    // families differ only while both are registered; with the site face gone
    // they resolve to the same fallback and come back byte-identical.
    //
    // It holds while the fallback is a DIFFERENT face that also covers Arabic,
    // which is exactly today's registry. If DejaVu ever leaves, or an unknown
    // family starts resolving against the registered pool instead of a built-in
    // fallback (as it does on the sibling project), this stops discriminating
    // and the canary above is what remains.
    $draw = fn (string $family): string => app(TakumiRenderer::class)->render(
        '<style>body{margin:0;background:#000;display:flex}</style>'
        ."<div style=\"width:600px;height:120px;display:flex;align-items:center;padding:0 20px;direction:rtl;font-family:'{$family}';font-size:48px;color:#fff\">علوم الحاسب</div>",
        600,
        scale: 1.0,
    );

    expect($draw('IBM Plex Sans Arabic'))->not->toBe($draw('DejaVu Sans Mono'));
});

it('renders the link preview card at the size the meta tags promise', function () {
    PageFactory::new()->create(['slug' => '/altkhssat', 'title' => 'التخصصات']);

    $png = app(OgImageService::class)->generateRouteScreenshot('/altkhssat', OgImageService::TYPE_OG);

    // The magic number rather than a mime sniff: these bytes are served as an
    // image to crawlers that will not retry, and "some file exists" is what
    // this test exists to rule out.
    expect(substr(file_get_contents($png), 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    $image = imagecreatefromstring(file_get_contents($png));

    expect(imagesx($image))->toBe(1440)
        ->and(imagesy($image))->toBe(756);

    // ...and what the page tells crawlers to expect is the same pair. A card
    // whose real size disagrees with og:image:width is cropped or letterboxed
    // by whoever believes the tag.
    $this->get('/altkhssat')
        ->assertSee('property="og:image:width" content="1440"', false)
        ->assertSee('property="og:image:height" content="756"', false)
        ->assertSee('property="og:image:type" content="image/png"', false);
});

it('grows the bot card with the page instead of cropping it to a box', function () {
    $service = app(OgImageService::class);

    $short = imagecreatefromstring(file_get_contents($service->generatePageScreenshot(
        PageFactory::new()->make(['slug' => '/qasir', 'title' => 'صفحة قصيرة', 'html_content' => 'سطر واحد.']),
        OgImageService::TYPE_BOT,
    )));

    $long = imagecreatefromstring(file_get_contents($service->generatePageScreenshot(
        PageFactory::new()->make([
            'slug' => '/taweel',
            'title' => 'صفحة طويلة',
            'html_content' => tipTapDocumentOf(str_repeat('فقرة تشرح تفصيلًا من تفاصيل الصفحة. ', 60)),
        ]),
        OgImageService::TYPE_BOT,
    )));

    // Width is fixed and doubled; height is the page's.
    expect(imagesx($short))->toBe(1440)
        ->and(imagesx($long))->toBe(1440);

    // The floor keeps a one-line page a poster rather than a strip...
    expect(imagesy($short))->toBe(1440);

    // ...and a page with more to say gets more card, which is the whole reason
    // this one is not a fixed box.
    expect(imagesy($long))->toBeGreaterThan(imagesy($short) + 900);

    // Bounded, though: Telegram is being handed this as a photo.
    expect(imagesy($long))->toBeLessThan(6000);
});

it('draws the page onto the card rather than an empty frame', function (string $type) {
    $image = renderShareCard(probePage(), $type);

    // --text on --bg: if the fonts failed to register, the layout still draws
    // and every one of these pixels disappears. This is the shape of the
    // text-less-screenshot failure the browser used to have, and the single
    // most valuable thing here to keep watching.
    expect(countCardPixelsNear($image, 'f4f6f8'))->toBeGreaterThan(500);

    // --primary: the brand rule, the mark's badge and the section pill. Losing
    // the stylesheet leaves the text but takes all of these with it.
    expect(countCardPixelsNear($image, '38a7bb'))->toBeGreaterThan(200);
})->with([OgImageService::TYPE_OG, OgImageService::TYPE_BOT]);

/**
 * A renderer that keeps the markup it was handed instead of drawing it.
 *
 * The pixels are covered by the tests above; what this one is for is the wiring
 * between the page and the template — that the content reached the card at all,
 * and in the shape the card's stylesheet knows how to draw.
 */
function capturingRenderer(): TakumiRenderer
{
    return new class extends TakumiRenderer
    {
        public string $captured = '';

        public function render(string $html, int $width, ?int $height = null, ?int $minHeight = null, float $scale = 2.0, string $lang = 'ar'): string
        {
            $this->captured = $html;

            $pixel = imagecreatetruecolor(1, 1);
            ob_start();
            imagepng($pixel);

            return (string) ob_get_clean();
        }
    };
}

/** A page carrying one of everything the transformer has to handle. */
function richPage(int $paragraphs = 1): Page
{
    $blocks = [
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'توزيع الدرجات']]],
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'الاختبار الأول عشرون درجة.']]]]],
        ]],
        ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [
                ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'البند']]]]],
                ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'الدرجة']]]]],
            ]],
        ]],
        ['type' => 'paragraph', 'content' => [[
            'type' => 'image',
            'attrs' => ['src' => 'data:image/png;base64,'.base64_encode(cardSamplePng()), 'alt' => 'رسم بياني'],
        ]]],
    ];

    for ($index = 0; $index < $paragraphs; $index++) {
        $blocks[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('نص يشرح تفاصيل التوزيع. ', 20)]]];
    }

    return PageFactory::new()->make([
        'slug' => '/allwaeh/tozee-aldrjat',
        'title' => 'توزيع الدرجات',
        'html_content' => ['type' => 'doc', 'content' => $blocks],
    ]);
}

/** A real PNG for the pages that embed one. */
function cardSamplePng(): string
{
    $image = imagecreatetruecolor(240, 120);
    imagefilledrectangle($image, 0, 0, 240, 120, imagecolorallocate($image, 41, 130, 135));

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

it('draws the page"s own content, not just its title', function () {
    $renderer = capturingRenderer();
    $this->instance(TakumiRenderer::class, $renderer);

    app(OgImageService::class)->generatePageScreenshot(richPage(), OgImageService::TYPE_BOT);

    // The screenshot this card replaced carried the page. So does this one: the
    // heading, the list, the table and the picture all reach the template, in
    // the vocabulary resources/views/social/content-style.blade.php styles.
    expect($renderer->captured)
        ->toContain('<div class="c-h2">توزيع الدرجات</div>')
        ->toContain('class="c-li"')
        ->toContain('class="c-table"')
        ->toContain('<img src="data:image/png;base64,');
});

it('styles the page"s content with the shared vocabulary, not just draws it', function () {
    $image = renderShareCard(PageFactory::new()->make([
        'slug' => '/allwaeh/tozee-aldrjat',
        'title' => 'توزيع الدرجات',
        'html_content' => ['type' => 'doc', 'content' => [
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'البند']]]]],
                    ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'الدرجة']]]]],
                ]],
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'الأول']]]]],
                    ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '٢٠']]]]],
                ]],
            ]],
        ]],
    ]), OgImageService::TYPE_BOT);

    // The tinted header row of a table — a colour only
    // resources/views/social/content-style.blade.php paints, and one no `<table>`
    // would produce on its own, since the engine does not lay tables out at all.
    // Losing that partial leaves the page's words on the card with none of the
    // shape that makes them readable, which no "did the text arrive" check can
    // see.
    expect(countCardPixelsNear($image, '293b42', tolerance: 6))->toBeGreaterThan(1000);
});

it('says so on the bot card when it stops short of the page', function () {
    $renderer = capturingRenderer();
    $this->instance(TakumiRenderer::class, $renderer);
    $service = app(OgImageService::class);

    $service->generatePageScreenshot(richPage(paragraphs: 1), OgImageService::TYPE_BOT);

    expect($renderer->captured)->not->toContain('تابع القراءة في الموقع');

    $service->generatePageScreenshot(richPage(paragraphs: 40), OgImageService::TYPE_BOT);

    // A page longer than the budget ends with an invitation rather than with a
    // sentence that simply stops.
    expect($renderer->captured)->toContain('تابع القراءة في الموقع');
});

it('keeps the link preview card off the page"s images', function () {
    $renderer = capturingRenderer();
    $this->instance(TakumiRenderer::class, $renderer);

    app(OgImageService::class)->generatePageScreenshot(richPage(), OgImageService::TYPE_OG);

    // Four visible lines is no place for a picture, and this card is drawn
    // inside a crawler's request — an image budget is what would let it wait on
    // somebody else's server. The picture is named instead.
    expect($renderer->captured)
        ->not->toContain('<img src="data:image/png;base64,')
        ->toContain('<div class="c-chip">صورة: رسم بياني</div>');
});

it('draws each of the page"s own words onto the card', function (array $overrides) {
    $base = [
        'slug' => '/altkhssat',
        'title' => 'التخصصات',
        'html_content' => 'تعرف على تخصصات كلية الحاسبات وخططها الدراسية ومساراتها المختلفة.',
    ];

    $service = app(OgImageService::class);

    // One field at a time, everything else held still — including the slug, so
    // the footer's URL cannot be what makes the two images differ. A template
    // that stopped interpolating this field would draw the same card twice.
    $before = file_get_contents($service->generatePageScreenshot(PageFactory::new()->make($base), OgImageService::TYPE_OG));
    $after = file_get_contents($service->generatePageScreenshot(PageFactory::new()->make([...$base, ...$overrides]), OgImageService::TYPE_OG));

    expect($before)->not->toBe($after);
})->with([
    'the title' => [['title' => 'الخطط الدراسية']],
    'the description' => [['html_content' => 'كل ما يخص القبول والتحويل بين مسارات الكلية في مكان واحد.']],
]);

it('draws the parent page as the card"s section', function () {
    $parent = PageFactory::new()->create(['slug' => '/altkhssat', 'title' => 'التخصصات']);
    $child = PageFactory::new()->childOf($parent)->create([
        'slug' => '/altkhssat/aloom-alhasb',
        'title' => 'علوم الحاسب',
    ]);

    $withSection = file_get_contents(
        app(OgImageService::class)->generatePageScreenshot($child->fresh(), OgImageService::TYPE_OG)
    );

    $child->parent()->dissociate()->save();

    $withoutSection = file_get_contents(
        app(OgImageService::class)->generatePageScreenshot($child->fresh(), OgImageService::TYPE_OG)
    );

    expect($withSection)->not->toBe($withoutSection);
});

it('reuses the rendered file until the card would say something else', function () {
    $service = app(OgImageService::class);
    $page = PageFactory::new()->create(['slug' => '/allwaeh', 'title' => 'اللوائح']);

    expect($service->hasPageScreenshot($page, OgImageService::TYPE_BOT))->toBeFalse();

    $first = $service->generatePageScreenshot($page, OgImageService::TYPE_BOT);

    expect($service->hasPageScreenshot($page, OgImageService::TYPE_BOT))->toBeTrue()
        ->and($service->generatePageScreenshot($page, OgImageService::TYPE_BOT))->toBe($first);

    $page->title = 'اللوائح والأنظمة';

    $second = $service->generatePageScreenshot($page, OgImageService::TYPE_BOT);

    // A new title is a new card, at a new URL — nothing had to be cleared for
    // that to happen — and the superseded file does not survive the edit.
    expect($second)->not->toBe($first)
        ->and(file_exists($first))->toBeFalse();
});

it('names a card file so the cleanup command can find its cache key back', function () {
    $page = PageFactory::new()->create(['slug' => '/allwaeh/alghyab', 'title' => 'الغياب']);
    $service = app(OgImageService::class);

    $filename = basename($service->generatePageScreenshot($page, OgImageService::TYPE_BOT));

    // `storage:cleanup --screenshots` reads "{type}_{identifier}.png" back into
    // "screenshot:{type}:{identifier}" and deletes any file whose key is gone.
    // If the two shapes ever drift, that command silently deletes every live
    // card instead of the orphans.
    expect($filename)->toMatch('/^bot_.+\.png$/');

    $identifier = substr($filename, strlen('bot_'), -strlen('.png'));

    expect(config('app-cache.keys.screenshot').':bot:'.$identifier)
        ->toBe($service->getPageCacheKey($page, OgImageService::TYPE_BOT))
        ->and(cache()->has($service->getPageCacheKey($page, OgImageService::TYPE_BOT)))->toBeTrue();
});

it('gives a route with no page behind it the site"s own card', function () {
    $service = app(OgImageService::class);

    // Nothing in the database answers to either path, so both fall back — but
    // the tools carry their own name and an unknown URL carries the site's, and
    // two different cards never share a file.
    $tool = $service->generateRouteScreenshot('/adwat/hasbh-almadl', OgImageService::TYPE_OG);
    $unknown = $service->generateRouteScreenshot('/la-yojad', OgImageService::TYPE_OG);

    expect(file_get_contents($tool))->not->toBe(file_get_contents($unknown));
});

it('serves the card as a PNG from the OG endpoint', function () {
    PageFactory::new()->create(['slug' => '/altkhssat', 'title' => 'التخصصات']);

    $response = $this->get(route('og-image', ['route' => 'altkhssat']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    // Symfony reorders the directives, so the configured string is checked
    // piece by piece rather than compared whole.
    foreach (explode(', ', ScreenshotConfig::cacheControl()) as $directive) {
        expect($response->headers->get('Cache-Control'))->toContain($directive);
    }
});

it('answers 500 rather than a broken image when a card cannot be drawn', function () {
    Log::spy();

    $this->instance(TakumiRenderer::class, new class extends TakumiRenderer
    {
        public function render(string $html, int $width, ?int $height = null, ?int $minHeight = null, float $scale = 2.0, string $lang = 'ar'): string
        {
            throw new RuntimeException('no engine here');
        }
    });

    // The preview is worth less than the page: the endpoint logs and answers,
    // it does not leak the exception to a crawler.
    $this->get(route('og-image', ['route' => 'altkhssat']))->assertStatus(500);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => str_contains($message, 'Failed to generate OG image'))
        ->once();
});

it('lets a failed card out of the bot"s hands instead of sending a reply without one', function () {
    $this->instance(TakumiRenderer::class, new class extends TakumiRenderer
    {
        public function render(string $html, int $width, ?int $height = null, ?int $minHeight = null, float $scale = 2.0, string $lang = 'ar'): string
        {
            throw new RuntimeException('no engine here');
        }
    });

    // The other half of the pair above, and the reason the service catches
    // nothing itself: the bot's page reply is the image, so a failure has to
    // reach the handler.
    expect(fn (): string => app(OgImageService::class)->generatePageScreenshot(
        PageFactory::new()->make(['slug' => '/allwaeh', 'title' => 'اللوائح']),
        OgImageService::TYPE_BOT,
    ))->toThrow(RuntimeException::class);
});
