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
