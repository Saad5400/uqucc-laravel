<?php

use App\Services\TelegramMarkdownService;

function telegramHtml(string $markdown): string
{
    return (new TelegramMarkdownService)->toTelegramHtml($markdown);
}

it('converts headings of every level to bold lines', function () {
    expect(telegramHtml('## مكافأة التفوق'))->toBe('<b>مكافأة التفوق</b>')
        ->and(telegramHtml('# عنوان'))->toBe('<b>عنوان</b>')
        ->and(telegramHtml('### متى تكون مؤهل؟'))->toBe('<b>متى تكون مؤهل؟</b>');
});

it('converts bold, italic and strikethrough to telegram entities', function () {
    expect(telegramHtml('تحتاج **شرطين** خلال السنة'))->toBe('تحتاج <b>شرطين</b> خلال السنة')
        ->and(telegramHtml('نص __غامق__ و _مائل_'))->toBe('نص <b>غامق</b> و <i>مائل</i>')
        ->and(telegramHtml('نص *مائل* و ~~محذوف~~'))->toBe('نص <i>مائل</i> و <s>محذوف</s>');
});

it('converts a two-column table with a header to key-value bullet lines', function () {
    $markdown = <<<'MD'
    | الفصل | المطلوب |
    |---|---|
    | الفصل الأول | معدل تراكمي ≥ 3.50 ✅ |
    | الفصل الثاني | معدل تراكمي ≥ 3.50 ✅ |
    MD;

    expect(telegramHtml($markdown))->toBe(
        "• <b>الفصل الأول</b>: معدل تراكمي ≥ 3.50 ✅\n\n"
        .'• <b>الفصل الثاني</b>: معدل تراكمي ≥ 3.50 ✅'
    );
});

it('labels wider table rows with their column headers', function () {
    $markdown = <<<'MD'
    | المقرر | الساعات | الدرجة |
    |---|---|---|
    | برمجة 1 | 3 | A |
    MD;

    expect(telegramHtml($markdown))->toBe('• <b>برمجة 1</b> — الساعات: 3 — الدرجة: A');
});

it('renders a headerless table as joined bullet lines', function () {
    $markdown = "| أ | ب | ج |\n| د | هـ | و |";

    expect(telegramHtml($markdown))->toBe("• <b>أ</b> — ب — ج\n\n• <b>د</b> — هـ — و");
});

it('converts list markers to bullets and keeps numbered lists', function () {
    expect(telegramHtml("- طلاب البكالوريوس فقط\n* منتظم\n1. أولاً"))
        ->toBe("• طلاب البكالوريوس فقط\n• منتظم\n1. أولاً");
});

it('preserves nested list indentation', function () {
    expect(telegramHtml("- أول\n  - متفرع"))->toBe("• أول\n  • متفرع");
});

it('converts links and keeps bare urls clickable', function () {
    expect(telegramHtml('راجع [المكافآت](https://uqucc.sb.sa/adwat/almkafa) للتفاصيل'))
        ->toBe('راجع <a href="https://uqucc.sb.sa/adwat/almkafa">المكافآت</a> للتفاصيل')
        ->and(telegramHtml('(المصدر: https://uqucc.sb.sa/algamaa/almaly/mkafa-alamtyaz)'))
        ->toBe('(المصدر: https://uqucc.sb.sa/algamaa/almaly/mkafa-alamtyaz)');
});

it('does not treat underscores inside urls as italic', function () {
    expect(telegramHtml('انظر https://example.com/a_b_c وأيضاً https://example.com/x_y_z'))
        ->toBe('انظر https://example.com/a_b_c وأيضاً https://example.com/x_y_z');
});

it('converts inline code and fenced code blocks', function () {
    expect(telegramHtml('استخدم الأمر `php artisan test` محلياً'))
        ->toBe('استخدم الأمر <code>php artisan test</code> محلياً')
        ->and(telegramHtml("```php\necho 1 < 2;\n```"))
        ->toBe('<pre>echo 1 &lt; 2;</pre>');
});

it('converts blockquotes to telegram blockquotes', function () {
    expect(telegramHtml("> ملاحظة مهمة\n> سطر ثانٍ"))
        ->toBe("<blockquote>ملاحظة مهمة\nسطر ثانٍ</blockquote>");
});

it('drops horizontal rules and collapses the leftover blank lines', function () {
    expect(telegramHtml("قبل\n\n---\n\nبعد"))->toBe("قبل\n\nبعد");
});

it('escapes raw html so telegram never receives stray tags', function () {
    expect(telegramHtml('الشرط: المعدل < 2 و <script>alert(1)</script>'))
        ->toBe('الشرط: المعدل &lt; 2 و &lt;script&gt;alert(1)&lt;/script&gt;');
});

it('converts a realistic assistant reply end to end', function () {
    $markdown = <<<'MD'
    ## 🎯 مكافأة التفوق — باختصار

    ### متى تكون مؤهل؟

    تحتاج **شرطين** خلال السنة:

    | الفصل | المطلوب |
    |---|---|
    | الفصل الأول | معدل ≥ 3.50 |

    - **طلاب البكالوريوس** فقط

    ⚠️ **ملاحظة:** لا تصرف إذا تجاوزت المدة.

    (المصدر: https://uqucc.sb.sa/algamaa/almaly/mkafa-alamtyaz)
    MD;

    expect(telegramHtml($markdown))->toBe(<<<'HTML'
    <b>🎯 مكافأة التفوق — باختصار</b>

    <b>متى تكون مؤهل؟</b>

    تحتاج <b>شرطين</b> خلال السنة:

    • <b>الفصل الأول</b>: معدل ≥ 3.50

    • <b>طلاب البكالوريوس</b> فقط

    ⚠️ <b>ملاحظة:</b> لا تصرف إذا تجاوزت المدة.

    (المصدر: https://uqucc.sb.sa/algamaa/almaly/mkafa-alamtyaz)
    HTML);
});

it('strips entity tags for the plain-text fallback', function () {
    $service = new TelegramMarkdownService;

    $plain = $service->toPlainText("<b>عنوان</b>\n• <b>الفصل الأول</b>: معدل &lt; 3.50");

    expect($plain)->toBe("عنوان\n• الفصل الأول: معدل < 3.50");
});
