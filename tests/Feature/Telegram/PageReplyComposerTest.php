<?php

use App\Models\Page;
use App\Services\Telegram\PageReply;
use App\Services\Telegram\PageReplyComposer;
use App\Support\TelegramHtml;

function composeReply(Page $page): PageReply
{
    return app(PageReplyComposer::class)->compose($page);
}

function docOf(array ...$blocks): array
{
    return ['type' => 'doc', 'content' => $blocks];
}

function textBlock(string $text): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
}

function imageBlock(string $name): array
{
    return ['type' => 'image', 'attrs' => ['src' => "/storage/uploads/{$name}"]];
}

/** A document of `$count` lines, each short enough that Telegram draws it on one. */
function shortLines(int $count): array
{
    return docOf(...array_map(fn (int $i): array => textBlock("سطر {$i}"), range(1, $count)));
}

/** A page whose reply is read straight from its content, the default for new pages. */
function contentPage(array $attributes = []): Page
{
    return Page::factory()->create(array_merge([
        'title' => 'التقديرات',
        'slug' => '/altkdyrat',
        'html_content' => docOf(textBlock('الدرجات تُحسب من مئة.'), textBlock('والنجاح من ستين.')),
        'quick_response_auto_extract_message' => true,
        'quick_response_auto_extract_buttons' => true,
        'quick_response_auto_extract_attachments' => true,
    ], $attributes))->fresh();
}

it('puts the linked title over short content, with no quote in the way', function () {
    $page = contentPage();

    $reply = composeReply($page);

    expect($reply->text)->toBe(
        '<a href="'.url('/altkdyrat').'"><b>التقديرات</b></a>'
        ."\n\nالدرجات تُحسب من مئة.\n\nوالنجاح من ستين."
    )
        ->and($reply->fallbackText)->toBeNull()
        ->and($reply->attachments)->toBe([])
        ->and($reply->previewUrl)->toBeNull()
        ->and($reply->keyboard)->toBe([])
        ->and(json_decode($reply->linkPreviewOptions(), true))->toBe(['is_disabled' => true])
        ->and($reply->replyMarkup())->toBeNull();
});

it('folds content taller than a screenful into a collapsed quote', function () {
    $reply = composeReply(contentPage(['html_content' => shortLines(20)]));

    expect($reply->text)->toBe(
        '<a href="'.url('/altkdyrat').'"><b>التقديرات</b></a>'
        ."\n\n<blockquote expandable>".implode("\n\n", array_map(fn (int $i): string => "سطر {$i}", range(1, 20))).'</blockquote>'
    )
        ->and($reply->fallbackText)->toContain('سطر 1')
        ->and($reply->fallbackText)->not->toContain('<blockquote');
});

it('decides by height, counting the content\'s own lines and the ones wrapping adds', function (array $document, bool $folded) {
    $reply = composeReply(contentPage(['html_content' => $document]));

    expect(str_contains($reply->text, '<blockquote'))->toBe($folded);
})->with([
    'six short lines' => [shortLines(6), false],
    'seven short lines' => [shortLines(7), true],
    'one paragraph wrapping to ten lines' => [docOf(textBlock(str_repeat('كلمة ', 80))), false],
    'one paragraph wrapping past the screen' => [docOf(textBlock(str_repeat('كلمة ', 120))), true],
]);

it('leaves the title unlinked when the link is off or the page has no public URL', function (array $attributes) {
    $reply = composeReply(contentPage($attributes));

    expect($reply->text)->toStartWith("<b>التقديرات</b>\n\nالدرجات")
        ->and($reply->text)->not->toContain('<a ');
})->with([
    'link switched off' => [['quick_response_send_link' => false]],
    'hidden from the website' => [['hidden' => true]],
]);

it('does not nest a quote inside a custom message that already carries one', function () {
    $reply = composeReply(contentPage([
        'quick_response_auto_extract_message' => false,
        'quick_response_message' => '<p>سؤال</p><blockquote expandable>جواب</blockquote>',
    ]));

    expect(substr_count($reply->text, '<blockquote'))->toBe(1)
        ->and($reply->text)->toContain("سؤال\n\n<blockquote expandable>جواب</blockquote>")
        ->and($reply->fallbackText)->toBeNull();
});

it('treats an editor\'s empty paragraph as no text at all', function () {
    $reply = composeReply(contentPage([
        'quick_response_auto_extract_message' => false,
        'quick_response_message' => '<p></p>',
    ]));

    expect($reply->text)->toBe('<a href="'.url('/altkdyrat').'"><b>التقديرات</b></a>')
        ->and($reply->fallbackText)->toBeNull();
});

it('cuts a long page to what one message holds and points to the rest', function () {
    $paragraphs = array_map(fn (int $i): array => textBlock("الفقرة {$i}: ".str_repeat('كلمة ', 30)), range(1, 60));

    $reply = composeReply(contentPage(['html_content' => docOf(...$paragraphs)]));

    expect(TelegramHtml::length($reply->text))->toBeLessThanOrEqual(4096)
        ->and($reply->text)->toContain('…</blockquote>')
        ->and($reply->text)->toEndWith('📖 <a href="'.url('/altkdyrat').'">تابع القراءة في الموقع</a>')
        ->and($reply->text)->toContain('الفقرة 1:')
        ->and($reply->text)->not->toContain('الفقرة 60:');
});

it('cuts a hidden page without offering a link it cannot honour', function () {
    $paragraphs = array_map(fn (int $i): array => textBlock(str_repeat('كلمة ', 40)), range(1, 60));

    $reply = composeReply(contentPage(['hidden' => true, 'html_content' => docOf(...$paragraphs)]));

    expect(TelegramHtml::length($reply->text))->toBeLessThanOrEqual(4096)
        ->and($reply->text)->toEndWith('…</blockquote>')
        ->and($reply->text)->not->toContain('تابع القراءة');
});

it('sends a page\'s few images along as attachments', function () {
    $reply = composeReply(contentPage([
        'html_content' => docOf(textBlock('خطوات التفعيل'), imageBlock('one.png'), imageBlock('two.png'), imageBlock('three.png')),
    ]));

    expect($reply->attachments)->toBe(['uploads/one.png', 'uploads/two.png', 'uploads/three.png'])
        ->and($reply->previewUrl)->toBeNull()
        ->and($reply->text)->not->toContain('🖼');
});

it('hands an image-heavy page to the website instead of an album', function () {
    $images = array_map(fn (int $i): array => imageBlock("shot-{$i}.png"), range(1, 5));

    $reply = composeReply(contentPage(['slug' => '/vscode', 'html_content' => docOf(textBlock('تثبيت المحرر'), ...$images)]));

    expect($reply->attachments)->toBe([])
        ->and($reply->previewUrl)->toBe(url('/vscode'))
        ->and($reply->text)->toEndWith('🖼 <a href="'.url('/vscode').'">الصور والخطوات المصوّرة في الموقع</a>')
        ->and(json_decode($reply->linkPreviewOptions(), true))->toBe(['url' => url('/vscode'), 'prefer_large_media' => true, 'show_above_text' => false]);
});

it('keeps the admin\'s own attachments on an image-heavy page', function () {
    $images = array_map(fn (int $i): array => imageBlock("shot-{$i}.png"), range(1, 5));

    $reply = composeReply(contentPage([
        'html_content' => docOf(textBlock('تثبيت المحرر'), ...$images),
        'quick_response_auto_extract_attachments' => false,
        'quick_response_attachments' => ['quick-responses/guide.pdf'],
    ]));

    expect($reply->attachments)->toBe(['quick-responses/guide.pdf']);
});

it('lays the page\'s buttons in rows by size, then the sub-pages one per row', function () {
    $page = contentPage([
        'quick_response_auto_extract_buttons' => false,
        'quick_response_buttons' => [
            ['text' => 'أ', 'url' => 'https://a.example', 'size' => 'half'],
            ['text' => 'ب', 'url' => 'https://b.example', 'size' => 'half'],
            ['text' => 'ج', 'url' => 'https://c.example', 'size' => 'full'],
            ['text' => '', 'url' => 'https://dropped.example', 'size' => 'full'],
        ],
    ]);
    $child = Page::factory()->childOf($page)->create(['title' => 'حساب المعدل', 'slug' => '/altkdyrat/hsab-almaadl']);

    $reply = composeReply($page);

    expect($reply->keyboard)->toBe([
        [['text' => 'أ', 'url' => 'https://a.example'], ['text' => 'ب', 'url' => 'https://b.example']],
        [['text' => 'ج', 'url' => 'https://c.example']],
        [['text' => 'حساب المعدل', 'url' => url($child->slug)]],
    ])
        ->and(json_decode($reply->replyMarkup(), true))->toHaveKey('inline_keyboard');
});

it('turns the page\'s own links into buttons when asked to', function () {
    $reply = composeReply(contentPage([
        'html_content' => docOf([
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'راجع '],
                ['type' => 'text', 'text' => 'اللائحة', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://uqu.edu.sa/rules']]]],
            ],
        ]),
    ]));

    expect($reply->keyboard)->toBe([[['text' => 'اللائحة', 'url' => 'https://uqu.edu.sa/rules']]])
        ->and($reply->text)->toContain('<a href="https://uqu.edu.sa/rules">اللائحة</a>');
});

it('never links a sub-page hidden from the website or from the bot, and folds a long list', function () {
    $section = contentPage(['html_content' => docOf()]);
    Page::factory()->childOf($section)->hidden()->create(['title' => 'مسودة', 'order' => 0]);
    Page::factory()->childOf($section)->hiddenFromBot()->create(['title' => 'للموقع فقط', 'order' => 0]);

    foreach (range(1, 12) as $index) {
        Page::factory()->childOf($section)->create(['title' => "صفحة {$index}", 'order' => $index]);
    }

    $keyboard = composeReply($section)->keyboard;

    expect($keyboard)->toHaveCount(11)
        ->and($keyboard[0][0]['text'])->toBe('صفحة 1')
        ->and($keyboard[9][0]['text'])->toBe('صفحة 10')
        ->and($keyboard[10][0])->toBe(['text' => 'عرض كل الصفحات (12)', 'url' => url($section->slug)])
        ->and(collect($keyboard)->flatten(1)->pluck('text'))->not->toContain('مسودة', 'للموقع فقط');
});

it('reads a legacy HTML page the way it reads an editor document', function () {
    $reply = composeReply(contentPage([
        'html_content' => '<h2>الشروط</h2><p>يلزم <strong>معدل</strong> لا يقل عن <em>٣</em>.</p><ul><li>الأول</li><li>الثاني</li></ul>',
    ]));

    expect($reply->text)->toContain("<b>الشروط</b>\n\nيلزم <b>معدل</b> لا يقل عن <i>٣</i>.\n\n• الأول\n• الثاني")
        ->and($reply->text)->not->toContain('<blockquote');
});
