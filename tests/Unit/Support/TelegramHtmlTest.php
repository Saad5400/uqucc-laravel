<?php

use App\Support\TelegramHtml;

it('counts the characters a reader sees, not the markup', function () {
    expect(TelegramHtml::length('<b>مرحبا</b> &amp; <a href="https://x.y/very/long/url">أهلاً</a>'))->toBe(13);
});

it('knows an empty paragraph from text', function () {
    expect(TelegramHtml::isBlank("\n\n <b> </b>\n"))->toBeTrue()
        ->and(TelegramHtml::isBlank('<b>a</b>'))->toBeFalse();
});

it('returns text that fits untouched', function () {
    $html = '<b>قصير</b> جداً';

    expect(TelegramHtml::truncate($html, 100))->toBe($html);
});

it('cuts on a word and closes every tag it left open', function () {
    $html = '<blockquote expandable><b>عنوان الفقرة</b> ثم نص طويل يمتد <i>كلمات <u>متداخلة</u> كثيرة</i> حتى النهاية</blockquote>';

    $cut = TelegramHtml::truncate($html, 40);

    expect(TelegramHtml::length($cut))->toBeLessThanOrEqual(40)
        ->and($cut)->toEndWith('…</u></i></blockquote>')
        ->and($cut)->toStartWith('<blockquote expandable><b>عنوان الفقرة</b> ثم نص طويل');
});

it('keeps a closing tag that arrives before the cut balanced with its opener', function () {
    $cut = TelegramHtml::truncate('<b>اسم</b> وصف طويل جداً جداً جداً جداً', 12);

    expect(substr_count($cut, '<b>'))->toBe(1)
        ->and(substr_count($cut, '</b>'))->toBe(1)
        ->and($cut)->toEndWith('…');
});

it('re-escapes the text it cut so an ampersand survives the round trip', function () {
    $cut = TelegramHtml::truncate('أ &amp; ب &lt;ج&gt; كلمة أخرى طويلة', 9);

    expect($cut)->toContain('&amp;')
        ->and($cut)->not->toContain('<ج>');
});
