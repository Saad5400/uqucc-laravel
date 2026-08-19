<?php

use App\Support\QuizContentHtml;

it('keeps allowed tags and a valid dir attribute', function () {
    $html = '<p dir="rtl">مرحباً</p><pre dir="ltr"><code>x = 1</code></pre>';

    expect(QuizContentHtml::sanitize($html))->toBe($html);
});

it('drops disallowed attributes but keeps the element', function () {
    expect(QuizContentHtml::sanitize('<p dir="rtl" class="x" onclick="y()">نص</p>'))
        ->toBe('<p dir="rtl">نص</p>');
});

it('drops an invalid dir value', function () {
    expect(QuizContentHtml::sanitize('<p dir="sideways">نص</p>'))
        ->toBe('<p>نص</p>');
});

it('unwraps a disallowed tag but keeps its text', function () {
    expect(QuizContentHtml::sanitize('<div><marquee>نص</marquee></div>'))
        ->toContain('نص')
        ->not->toContain('marquee');
});

it('removes script and style with their contents', function () {
    $out = QuizContentHtml::sanitize('<p dir="rtl">آمن</p><script>alert(1)</script><style>p{}</style>');

    expect($out)->toBe('<p dir="rtl">آمن</p>')
        ->not->toContain('alert')
        ->not->toContain('p{}');
});

it('preserves plain Arabic text untouched', function () {
    $text = 'ما البوابة المنطقية التي تعكس قيمة المدخل؟';

    expect(QuizContentHtml::sanitize($text))->toBe($text);
});

it('measures text length ignoring the markup', function () {
    expect(QuizContentHtml::textLength('<p dir="rtl">أربعة</p>'))->toBe(5)
        ->and(QuizContentHtml::textLength(''))->toBe(0);
});

it('renders plain text with paragraph breaks', function () {
    expect(QuizContentHtml::toPlainText('<p dir="rtl">سطر</p><p dir="rtl">آخر</p>'))
        ->toBe("سطر\nآخر");
});
