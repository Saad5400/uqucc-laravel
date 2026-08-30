<?php

use App\Support\Disk;
use App\Support\SocialCardContent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Page content, rewritten for the engine
|--------------------------------------------------------------------------
|
| App\Support\SocialCardContent is the piece that puts the page back on the
| share cards. It is a whitelist: the TipTap document (or the legacy HTML) goes
| in, and the small vocabulary of flex boxes the card templates style comes out.
|
| These tests are about the SHAPE of that output rather than its pixels — the
| rendering tests next door take the same markup all the way to an image. What
| is worth pinning here is everything the engine cannot forgive: a tag that
| would get no box, a table that is not a layout, an image with no bytes and no
| size, a document deep or long enough to run away with the process.
|
*/

/** A TipTap text node, optionally marked. */
function tipTapText(string $text, string ...$marks): array
{
    return array_filter([
        'type' => 'text',
        'text' => $text,
        'marks' => array_map(fn (string $type): array => ['type' => $type], $marks) ?: null,
    ]);
}

/** A TipTap document wrapping the given block nodes. */
function tipTapDoc(array ...$blocks): array
{
    return ['type' => 'doc', 'content' => $blocks];
}

function tipTapParagraph(array ...$inline): array
{
    return ['type' => 'paragraph', 'content' => $inline];
}

/** A real PNG, small enough to embed and big enough to have a size. */
function samplePng(int $width = 240, int $height = 120): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 41, 130, 135));

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/** The body for some content, with room to spare unless a test says otherwise. */
function cardBody(array|string|null $content, int $characters = 4000, int $images = 4): App\Support\SocialCardBody
{
    return app(SocialCardContent::class)->build($content, $characters, $images, 632);
}

it('rewrites the blocks a page is made of into the card vocabulary', function () {
    $body = cardBody(tipTapDoc(
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [tipTapText('المتطلبات العامة')]],
        ['type' => 'heading', 'attrs' => ['level' => 4], 'content' => [tipTapText('تفصيل')]],
        tipTapParagraph(tipTapText('يجب إنهاء '), tipTapText('١٣٦ ساعة', 'bold'), tipTapText(' بحد أدنى.')),
        ['type' => 'blockquote', 'content' => [tipTapParagraph(tipTapText('ملاحظة مهمة.'))]],
        ['type' => 'horizontalRule'],
        ['type' => 'codeBlock', 'content' => [tipTapText("int x = 1;\nprint(x);")]],
    ));

    expect($body->html)
        ->toContain('<div class="c-h2">المتطلبات العامة</div>')
        // Six heading levels collapse to two: a card is too short for a scale.
        ->toContain('<div class="c-h3">تفصيل</div>')
        ->toContain('<strong>١٣٦ ساعة</strong>')
        ->toContain('class="c-quote"')
        ->toContain('<div class="c-hr"></div>')
        ->toContain('<pre class="c-pre">int x = 1;')
        ->and($body->truncated)->toBeFalse();
});

it('puts a run"s own weight on the outside of its other marks', function () {
    $body = cardBody(tipTapDoc(tipTapParagraph(tipTapText('مهم', 'bold', 'italic', 'link'))));

    // The engine drops a run's weight when an ancestor sets a non-default one,
    // so <strong> has to be the outermost wrapper. Nothing else here changes
    // weight, so nothing else can be taken away by what wraps it.
    expect($body->html)->toContain('<strong><em><span class="c-link">مهم</span></em></strong>');
});

it('draws a list as rows that carry their own markers', function () {
    $body = cardBody(tipTapDoc([
        'type' => 'bulletList',
        'content' => [
            ['type' => 'listItem', 'content' => [
                tipTapParagraph(tipTapText('المستوى الأول')),
                ['type' => 'orderedList', 'content' => [
                    ['type' => 'listItem', 'content' => [tipTapParagraph(tipTapText('متداخل'))]],
                ]],
            ]],
        ],
    ]));

    // `list-style` draws nothing in the engine, so the marker is a real element.
    expect($body->html)
        ->toContain('<span class="c-li-mark">•</span>')
        ->toContain('c-li-nested')
        ->toContain('<span class="c-li-mark">1.</span>')
        ->toContain('متداخل');
});

it('lays a table out as rows of cells rather than as a table', function () {
    $rows = [];

    foreach (range(1, 12) as $index) {
        $rows[] = ['type' => 'tableRow', 'content' => [
            ['type' => $index === 1 ? 'tableHeader' : 'tableCell', 'content' => [tipTapParagraph(tipTapText('بند '.$index))]],
            ['type' => $index === 1 ? 'tableHeader' : 'tableCell', 'content' => [tipTapParagraph(tipTapText('قيمة '.$index))]],
        ]];
    }

    $body = cardBody(tipTapDoc(['type' => 'table', 'content' => $rows]));

    expect($body->html)
        ->toContain('class="c-table"')
        ->toContain('class="c-tr c-tr-head"')
        ->toContain('<div class="c-td">بند 1</div>')
        // Twelve rows is a page's table, not a card's: the rest is summarised.
        ->not->toContain('بند 12')
        ->toContain('c-td-more')
        ->and($body->truncated)->toBeTrue();
});

it('embeds an image with the size the engine needs to draw it', function () {
    $png = 'data:image/png;base64,'.base64_encode(samplePng(240, 120));

    $body = cardBody(tipTapDoc(tipTapParagraph(['type' => 'image', 'attrs' => ['src' => $png, 'alt' => 'رسم']])));

    expect($body->html)
        ->toContain('<div class="c-figure">')
        ->toContain('src="data:image/png;base64,')
        // 240 × 120 fits the 632px column untouched; the box has to be explicit
        // either way, because the engine will not measure the bytes for us.
        ->toContain('width="240" height="120"');
});

it('scales an image down to the column it has to fit', function () {
    $png = 'data:image/png;base64,'.base64_encode(samplePng(1264, 632));

    $body = cardBody(tipTapDoc(tipTapParagraph(['type' => 'image', 'attrs' => ['src' => $png]])));

    expect($body->html)->toContain('width="632" height="316"');
});

it('names an image it has no room to draw instead of dropping it', function () {
    $png = 'data:image/png;base64,'.base64_encode(samplePng());

    $body = cardBody(tipTapDoc(tipTapParagraph(['type' => 'image', 'attrs' => ['src' => $png, 'alt' => 'رسم بياني']])), images: 0);

    expect($body->html)
        ->toContain('<div class="c-chip">صورة: رسم بياني</div>')
        ->not->toContain('<img');
});

it('reads an image the site stores off the media disk', function () {
    Storage::fake(Disk::MEDIA);
    Storage::disk(Disk::MEDIA)->put('pages/chart.png', samplePng(300, 150));

    $body = cardBody(tipTapDoc(tipTapParagraph([
        'type' => 'image',
        'attrs' => ['src' => '/storage/pages/chart.png'],
    ])));

    expect($body->html)->toContain('src="data:image/png;base64,')
        ->toContain('width="300" height="150"');
});

it('fetches an external image only for the card that has a budget for one', function () {
    Http::fake(['*' => Http::response(samplePng(200, 100), 200, ['Content-Type' => 'image/png'])]);

    $document = tipTapDoc(tipTapParagraph([
        'type' => 'image',
        'attrs' => ['src' => 'https://example.com/chart.png', 'alt' => 'خارجي'],
    ]));

    expect(cardBody($document)->html)->toContain('src="data:image/png;base64,');

    Http::assertSentCount(1);
    Http::fake(['*' => Http::response(samplePng(), 200, ['Content-Type' => 'image/png'])]);

    // The link-preview card is built inside a crawler's request with an image
    // budget of zero, and this is the property that keeps it off the network:
    // not "it fetches quickly", but "it never asks".
    expect(cardBody($document, images: 0)->html)->toContain('c-chip');

    Http::assertNothingSent();
});

it('leaves out an external image that is not one', function () {
    Http::fake(['*' => Http::response('<html>nope</html>', 200, ['Content-Type' => 'text/html'])]);

    $body = cardBody(tipTapDoc(tipTapParagraph([
        'type' => 'image',
        'attrs' => ['src' => 'https://example.com/not-an-image', 'alt' => 'صورة'],
    ])));

    expect($body->html)->toContain('c-chip')->not->toContain('<img');
});

it('labels the things a card cannot play instead of leaving a hole', function () {
    $body = cardBody('<p>قبل</p><iframe src="https://youtube.com/embed/x"></iframe><video src="a.mp4"></video><p>بعد</p>');

    expect($body->html)
        ->toContain('<div class="c-chip">محتوى مضمّن</div>')
        ->toContain('<div class="c-chip">مقطع مرئي</div>')
        ->toContain('قبل')
        ->toContain('بعد');
});

it('drops script and style rather than unwrapping them into the card', function () {
    $body = cardBody('<p>نص</p><script>alert("x")</script><style>body{display:none}</style>');

    expect($body->html)
        ->toContain('نص')
        ->not->toContain('alert')
        ->not->toContain('display:none');
});

it('escapes what the page says instead of letting it become markup', function () {
    $body = cardBody(tipTapDoc(tipTapParagraph(tipTapText('<script>alert(1)</script> & "اقتباس"'))));

    expect($body->html)
        ->not->toContain('<script>')
        ->toContain('&lt;script&gt;')
        ->toContain('&amp;');
});

it('keeps the text of a node it has never seen before', function () {
    // The editor gains extensions. An unknown block that emptied the card would
    // be a silent, site-wide regression; one that comes out unstyled is a
    // cosmetic one.
    $body = cardBody(tipTapDoc([
        'type' => 'someFutureExtension',
        'content' => [tipTapParagraph(tipTapText('محتوى من امتداد جديد'))],
    ]));

    expect($body->html)->toContain('محتوى من امتداد جديد');
});

it('opens the editor"s custom blocks, which a card cannot fold', function () {
    $body = cardBody(tipTapDoc(
        ['type' => 'customBlock', 'attrs' => ['id' => 'alert', 'config' => ['content' => '<p>تنبيه مهم</p>']]],
        // The same contract, stored the other way it is stored: a JSON string.
        ['type' => 'customBlock', 'attrs' => ['id' => 'collapsible', 'config' => json_encode([
            'question' => 'هل يمكن التحويل؟',
            'answer' => '<p>نعم بشروط.</p>',
        ], JSON_UNESCAPED_UNICODE)]],
    ));

    expect($body->html)
        ->toContain('<div class="c-alert">')
        ->toContain('تنبيه مهم')
        ->toContain('<div class="c-collapse-q">هل يمكن التحويل؟</div>')
        ->toContain('نعم بشروط.');
});

it('reads a legacy page written as plain HTML', function () {
    $body = cardBody('<h2>عنوان</h2><p>فقرة فيها <strong>تشديد</strong> و<a href="https://x">رابط</a>.</p><ul><li>أول<ul><li>متداخل</li></ul></li></ul><table><tr><th>أ</th><th>ب</th></tr><tr><td>١</td><td>٢</td></tr></table>');

    expect($body->html)
        ->toContain('<div class="c-h2">عنوان</div>')
        ->toContain('<strong>تشديد</strong>')
        ->toContain('<span class="c-link">رابط</span>')
        ->toContain('<span class="c-li-mark">◦</span>')
        ->toContain('class="c-tr c-tr-head"');
});

it('keeps a Latin run inside an Arabic sentence', function () {
    $body = cardBody(tipTapDoc(tipTapParagraph(
        tipTapText('نفّذ الأمر '),
        tipTapText('npm run dev', 'code'),
        tipTapText(' ثم افتح المتصفح.'),
    )));

    expect($body->html)
        ->toContain('نفّذ الأمر')
        ->toContain('<span class="c-code">npm run dev</span>')
        ->toContain('ثم افتح المتصفح.');
});

it('stops at the budget and says that it did', function () {
    $long = str_repeat('كلمة ', 400);

    $body = cardBody(tipTapDoc(tipTapParagraph(tipTapText($long))), characters: 120);

    expect($body->truncated)->toBeTrue()
        ->and($body->html)->toContain('…')
        // Cut at a word boundary, and to the budget rather than near it.
        ->and(mb_strlen(strip_tags($body->html)))->toBeLessThanOrEqual(121);
});

it('does not report a truncation it did not make', function () {
    $body = cardBody(tipTapDoc(tipTapParagraph(tipTapText('فقرة قصيرة.'))), characters: 500);

    expect($body->truncated)->toBeFalse();
});

it('refuses to follow a document all the way down', function () {
    $nest = function (int $depth): array {
        $node = tipTapParagraph(tipTapText('في القاع'));

        for ($level = 0; $level < $depth; $level++) {
            $node = ['type' => 'blockquote', 'content' => [$node]];
        }

        return tipTapDoc($node);
    };

    // A pathological nest — a bad import, a paste from somewhere strange — has
    // to come back as a card without that text in it, not as a stack overflow.
    expect(cardBody($nest(400))->html)->not->toContain('في القاع');

    // ...while the depth a real page actually reaches is nowhere near the cap.
    expect(cardBody($nest(3))->html)->toContain('في القاع');
});

it('has nothing to say about a page with no content', function () {
    expect(cardBody(null)->isEmpty())->toBeTrue()
        ->and(cardBody('')->isEmpty())->toBeTrue()
        ->and(cardBody(['type' => 'doc', 'content' => []])->isEmpty())->toBeTrue()
        ->and(cardBody(null)->truncated)->toBeFalse();
});
