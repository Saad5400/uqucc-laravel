import { describe, expect, it } from 'vitest';
import { renderMarkdown } from './markdown';

describe('renderMarkdown', () => {
    it('renders paragraphs and line breaks', () => {
        expect(renderMarkdown('سطر أول\nسطر ثانٍ\n\nفقرة جديدة')).toBe('<p>سطر أول<br>سطر ثانٍ</p><p>فقرة جديدة</p>');
    });

    it('renders bold, italic and inline code', () => {
        expect(renderMarkdown('نص **مهم** و *مائل* و `code`')).toBe('<p>نص <strong>مهم</strong> و <em>مائل</em> و <code>code</code></p>');
    });

    it('does not treat plain numbers as code placeholders', () => {
        expect(renderMarkdown('المعدل 3 من `4`')).toBe('<p>المعدل 3 من <code>4</code></p>');
    });

    it('renders unordered and ordered lists', () => {
        expect(renderMarkdown('- أول\n- ثانٍ')).toBe('<ul><li>أول</li><li>ثانٍ</li></ul>');
        expect(renderMarkdown('1. أول\n2. ثانٍ')).toBe('<ol><li>أول</li><li>ثانٍ</li></ol>');
    });

    it('renders headings clamped between h2 and h4', () => {
        expect(renderMarkdown('# عنوان')).toBe('<h2>عنوان</h2>');
        expect(renderMarkdown('###### عنوان')).toBe('<h4>عنوان</h4>');
    });

    it('renders fenced code blocks without inline formatting', () => {
        expect(renderMarkdown('```php\n$x = **1**;\n```')).toBe('<pre><code>$x = **1**;</code></pre>');
    });

    it('renders safe links and drops unsafe protocols', () => {
        expect(renderMarkdown('[الدليل](/allwaeh)')).toBe('<p><a href="/allwaeh" target="_blank" rel="noopener noreferrer">الدليل</a></p>');
        expect(renderMarkdown('[سيئ](javascript:alert(1))')).not.toContain('<a');
    });

    it('autolinks bare urls as pressable ltr islands', () => {
        expect(renderMarkdown('https://uqucc.sb.sa/adwat/almkafa')).toBe(
            '<p><a href="https://uqucc.sb.sa/adwat/almkafa" target="_blank" rel="noopener noreferrer" dir="ltr">' +
                'https://uqucc.sb.sa/adwat/almkafa</a></p>',
        );
    });

    it('keeps trailing punctuation outside an autolinked url', () => {
        expect(renderMarkdown('المصدر: (https://uqucc.sb.sa/allwaeh/altkdyrat).')).toBe(
            '<p>المصدر: (<a href="https://uqucc.sb.sa/allwaeh/altkdyrat" target="_blank" rel="noopener noreferrer" dir="ltr">' +
                'https://uqucc.sb.sa/allwaeh/altkdyrat</a>).</p>',
        );
    });

    it('does not double-link a url inside an explicit markdown link', () => {
        expect(renderMarkdown('[الدليل](https://uqucc.sb.sa/allwaeh)')).toBe(
            '<p><a href="https://uqucc.sb.sa/allwaeh" target="_blank" rel="noopener noreferrer">الدليل</a></p>',
        );
    });

    it('does not autolink unsafe protocols', () => {
        const html = renderMarkdown('javascript:alert(1) و ftp://example.com/x');

        expect(html).not.toContain('<a');
    });

    it('escapes raw html from the model', () => {
        const html = renderMarkdown('<script>alert(1)</script> و <img src=x onerror=alert(1)>');

        expect(html).not.toContain('<script');
        expect(html).not.toContain('<img');
        expect(html).toContain('&lt;script&gt;');
    });

    it('renders blockquotes', () => {
        expect(renderMarkdown('> اقتباس')).toBe('<blockquote><p>اقتباس</p></blockquote>');
    });

    it('renders GFM tables with a head and body', () => {
        const table = '| المادة | الدرجة |\n| --- | --- |\n| رياضيات | 95 |\n| فيزياء | 88 |';

        expect(renderMarkdown(table)).toBe(
            '<table><thead><tr><th>المادة</th><th>الدرجة</th></tr></thead>' +
                '<tbody><tr><td>رياضيات</td><td>95</td></tr><tr><td>فيزياء</td><td>88</td></tr></tbody></table>',
        );
    });

    it('applies logical alignment from delimiter colons', () => {
        const table = '| اسم | قيمة |\n| :--- | ---: |\n| أ | 1 |';
        const html = renderMarkdown(table);

        expect(html).toContain('<th style="text-align: start">اسم</th>');
        expect(html).toContain('<th style="text-align: end">قيمة</th>');
        expect(html).toContain('<td style="text-align: end">1</td>');
    });

    it('does not treat a plain paragraph with a pipe as a table', () => {
        expect(renderMarkdown('الخيار أ | الخيار ب')).toBe('<p>الخيار أ | الخيار ب</p>');
    });
});
