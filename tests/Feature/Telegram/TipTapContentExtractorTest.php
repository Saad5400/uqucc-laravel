<?php

use App\Models\Page;
use App\Services\TipTapContentExtractor;

function extractMessage(array $content): ?string
{
    $page = Page::factory()->create(['html_content' => ['type' => 'doc', 'content' => $content]]);

    return app(TipTapContentExtractor::class)->getExtractedContent($page)['message'];
}

function paragraph(string $text, array $marks = []): array
{
    $node = ['type' => 'text', 'text' => $text];

    if ($marks !== []) {
        $node['marks'] = $marks;
    }

    return ['type' => 'paragraph', 'content' => [$node]];
}

it('wraps blockquotes in expandable telegram quotes', function () {
    $message = extractMessage([
        paragraph('هل ضروري نفعّل البطاقة الجامعية؟'),
        ['type' => 'blockquote', 'content' => [paragraph('الطالبات: نعم، والطلاب يُفضّل.')]],
    ]);

    expect($message)->toBe(
        "هل ضروري نفعّل البطاقة الجامعية؟\n\n<blockquote expandable>الطالبات: نعم، والطلاب يُفضّل.</blockquote>"
    );
});

it('keeps inline marks and links inside expandable quotes', function () {
    $message = extractMessage([
        ['type' => 'blockquote', 'content' => [
            paragraph('مهم', [['type' => 'bold']]),
            paragraph('الشرح', [['type' => 'link', 'attrs' => ['href' => 'https://t.me/uqucc_chat/1']]]),
        ]],
    ]);

    expect($message)->toBe(
        "<blockquote expandable><b>مهم</b>\n\n<a href=\"https://t.me/uqucc_chat/1\">الشرح</a></blockquote>"
    );
});

it('flattens nested blockquotes into a single expandable quote', function () {
    $message = extractMessage([
        ['type' => 'blockquote', 'content' => [
            paragraph('الجواب الخارجي'),
            ['type' => 'blockquote', 'content' => [paragraph('اقتباس داخلي')]],
        ]],
    ]);

    expect($message)->toBe(
        "<blockquote expandable>الجواب الخارجي\n\nاقتباس داخلي</blockquote>"
    )->not->toContain('<blockquote expandable><blockquote');
});

it('skips empty blockquotes entirely', function () {
    $message = extractMessage([
        paragraph('سؤال بدون جواب'),
        ['type' => 'blockquote', 'content' => [['type' => 'paragraph']]],
    ]);

    expect($message)->toBe('سؤال بدون جواب');
});

it('keeps multiple sibling quotes as separate expandable sections', function () {
    $message = extractMessage([
        ['type' => 'blockquote', 'content' => [paragraph('الجواب الأول')]],
        ['type' => 'blockquote', 'content' => [paragraph('الجواب الثاني')]],
    ]);

    expect($message)->toBe(
        "<blockquote expandable>الجواب الأول</blockquote>\n\n<blockquote expandable>الجواب الثاني</blockquote>"
    );
});

function extractAll(mixed $content): array
{
    $page = Page::factory()->create(['html_content' => $content]);

    return app(TipTapContentExtractor::class)->getExtractedContent($page);
}

function cell(string $type, string $text): array
{
    return ['type' => $type, 'content' => [paragraph($text)]];
}

it('draws a table as one line per row with bold headers', function () {
    $message = extractMessage([
        ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [cell('tableHeader', 'البند'), cell('tableHeader', 'الدرجة')]],
            ['type' => 'tableRow', 'content' => [cell('tableCell', 'الأول'), cell('tableCell', '٢٠')]],
            ['type' => 'tableRow', 'content' => [cell('tableCell', 'النهائي'), cell('tableCell', '٤٠')]],
        ]],
    ]);

    expect($message)->toBe("<b>البند</b> | <b>الدرجة</b>\nالأول | ٢٠\nالنهائي | ٤٠");
});

it('numbers an ordered list and bullets an unordered one', function () {
    $message = extractMessage([
        ['type' => 'orderedList', 'attrs' => ['start' => 1], 'content' => [
            ['type' => 'listItem', 'content' => [paragraph('سجّل الدخول')]],
            ['type' => 'listItem', 'content' => [paragraph('افتح الجدول')]],
        ]],
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [paragraph('ملاحظة')]],
        ]],
    ]);

    expect($message)->toBe("1. سجّل الدخول\n2. افتح الجدول\n\n• ملاحظة");
});

it('reads the editor\'s custom blocks: an alert\'s content and a collapsible\'s question and answer', function () {
    $message = extractMessage([
        ['type' => 'customBlock', 'attrs' => ['id' => 'alert', 'config' => ['icon' => 'solar:info-circle-linear', 'content' => '<p>انتبه إلى <strong>الموعد</strong></p>']]],
        ['type' => 'customBlock', 'attrs' => ['id' => 'collapsible', 'config' => ['question' => 'كيف أسجل؟', 'answer' => '<p>من <u>البوابة</u></p>']]],
    ]);

    expect($message)->toBe("انتبه إلى <b>الموعد</b>\n\n<b>كيف أسجل؟</b>\nمن <u>البوابة</u>");
});

it('counts the page\'s images apart from the attachments it collects', function () {
    $extracted = extractAll(['type' => 'doc', 'content' => [
        paragraph('خطوات'),
        ['type' => 'image', 'attrs' => ['src' => '/storage/uploads/a.png']],
        ['type' => 'image', 'attrs' => ['src' => 'https://cdn.example.com/b.png']],
    ]]);

    expect($extracted['images'])->toBe(2)
        ->and($extracted['attachments'])->toBe(['uploads/a.png', 'https://cdn.example.com/b.png']);
});

it('reads a legacy HTML page with its headings, marks, lists, tables and images', function () {
    $extracted = extractAll(
        '<h2>الشروط</h2><p>يلزم <strong>معدل</strong> لا يقل عن <em>٣</em> <a href="https://uqu.edu.sa/rules">حسب اللائحة</a>.</p>'
        .'<ol><li>الأول</li><li>الثاني</li></ol>'
        .'<table><tr><th>البند</th><th>الدرجة</th></tr><tr><td>الأول</td><td>٢٠</td></tr></table>'
        .'<img src="/storage/uploads/map.png"><hr><pre>x = 1</pre>'
    );

    expect($extracted['message'])->toBe(
        "<b>الشروط</b>\n\nيلزم <b>معدل</b> لا يقل عن <i>٣</i> <a href=\"https://uqu.edu.sa/rules\">حسب اللائحة</a>.\n\n"
        ."1. الأول\n2. الثاني\n\n"
        ."<b>البند</b> | <b>الدرجة</b>\nالأول | ٢٠\n\n"
        ."———\n\n<pre>x = 1</pre>"
    )
        ->and($extracted['buttons'])->toBe([['text' => 'حسب اللائحة', 'url' => 'https://uqu.edu.sa/rules', 'size' => 'full']])
        ->and($extracted['attachments'])->toBe(['uploads/map.png'])
        ->and($extracted['images'])->toBe(1);
});
