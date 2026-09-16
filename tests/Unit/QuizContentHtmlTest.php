<?php

use App\Helpers\Bidi;
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

/*
|--------------------------------------------------------------------------
| Direction marks
|--------------------------------------------------------------------------
|
| The card's layout engine reads `dir` on a block and ignores it on an inline
| element, so every inline run's direction has to reach it as characters
| instead. The marks are zero-width: the assertions below are about ordering
| instructions, never about the text itself.
|
*/

it('fences an inline ltr run between isolates', function () {
    expect(QuizContentHtml::withDirectionMarks('<p dir="rtl">القيم <span dir="ltr">(255, 0, 0)</span> تعني</p>'))
        ->toBe('<p dir="rtl">القيم <span dir="ltr">'.Bidi::LRI.'(255, 0, 0)'.Bidi::PDI.'</span> تعني</p>');
});

it('fences an inline rtl or auto run with the isolate it asked for', function () {
    expect(QuizContentHtml::withDirectionMarks('<span dir="rtl">عربي</span>'))
        ->toBe('<span dir="rtl">'.Bidi::RLI.'عربي'.Bidi::PDI.'</span>')
        ->and(QuizContentHtml::withDirectionMarks('<em dir="auto">x</em>'))
        ->toBe('<em dir="auto">'.Bidi::FSI.'x'.Bidi::PDI.'</em>');
});

it('fences inline code first-strong even without a dir of its own', function () {
    expect(QuizContentHtml::withDirectionMarks('<p dir="rtl">مثل <code>-6</code> هنا</p>'))
        ->toBe('<p dir="rtl">مثل <code>'.Bidi::FSI.'-6'.Bidi::PDI.'</code> هنا</p>');
});

it('leaves block direction to the engine, which already honours it', function () {
    $html = '<p dir="rtl">نص</p><pre dir="ltr"><code>x = (1, 2)</code></pre>';

    expect(QuizContentHtml::withDirectionMarks($html))->toBe($html);
});

it('keeps the fenced text itself unchanged', function () {
    $marked = QuizContentHtml::withDirectionMarks('<p dir="rtl">ناتج <span dir="ltr">f(3) = -6</span> هو</p>');

    expect(QuizContentHtml::toPlainText(str_replace([Bidi::LRI, Bidi::RLI, Bidi::FSI, Bidi::PDI], '', $marked)))
        ->toBe('ناتج f(3) = -6 هو');
});

it('leaves a fragment with no markup alone', function () {
    expect(QuizContentHtml::withDirectionMarks('ما ناتج ١ + ١؟'))->toBe('ما ناتج ١ + ١؟')
        ->and(QuizContentHtml::withDirectionMarks(''))->toBe('');
});
