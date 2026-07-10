import type { TipTapDoc, TipTapNode } from './extensions';

/**
 * Contract fixtures in the STORED format (what `pages.html_content` holds and what
 * both `RichContentRenderer.vue` and `App\Services\TipTapContentExtractor` consume).
 *
 * Attrs are written exactly as ProseMirror serializes them with the editor schema
 * (all declared attributes present, defaults included), so a round-trip through the
 * editor must reproduce these documents byte-for-byte:
 * - `textAlign: null` on paragraph/heading/tableCell (TextAlign default)
 * - link marks carry `target`/`rel`/`class` defaults from @tiptap/extension-link
 * - custom blocks are stored as `customBlock` nodes with `id`/`config`/`label`/`preview`
 */

const paragraph = (content?: TipTapNode[], textAlign: string | null = null): TipTapNode => ({
    type: 'paragraph',
    attrs: { textAlign },
    ...(content ? { content } : {}),
});

export const paragraphMarksDoc: TipTapDoc = {
    type: 'doc',
    content: [
        paragraph([
            { type: 'text', text: 'نص عادي ' },
            { type: 'text', marks: [{ type: 'bold' }], text: 'عريض' },
            { type: 'text', text: ' و ' },
            { type: 'text', marks: [{ type: 'italic' }], text: 'مائل' },
            { type: 'text', text: ' و ' },
            { type: 'text', marks: [{ type: 'underline' }], text: 'مسطّر' },
            { type: 'text', text: ' و ' },
            { type: 'text', marks: [{ type: 'strike' }], text: 'مشطوب' },
            { type: 'text', text: ' و ' },
            { type: 'text', marks: [{ type: 'code' }], text: 'كود مضمّن' },
            { type: 'text', text: ' و ' },
            {
                type: 'text',
                marks: [
                    {
                        type: 'link',
                        attrs: {
                            href: 'https://uqu.edu.sa/computer-college',
                            target: '_blank',
                            rel: 'noopener noreferrer nofollow',
                            class: null,
                        },
                    },
                ],
                text: 'رابط الكلية',
            },
        ]),
    ],
};

export const headingsDoc: TipTapDoc = {
    type: 'doc',
    content: [
        { type: 'heading', attrs: { textAlign: null, level: 2 }, content: [{ type: 'text', text: 'عنوان ثانٍ' }] },
        { type: 'heading', attrs: { textAlign: null, level: 3 }, content: [{ type: 'text', text: 'عنوان ثالث' }] },
        { type: 'heading', attrs: { textAlign: 'center', level: 4 }, content: [{ type: 'text', text: 'عنوان رابع موسّط' }] },
        paragraph([{ type: 'text', text: 'فقرة بعد العناوين' }]),
    ],
};

export const listsDoc: TipTapDoc = {
    type: 'doc',
    content: [
        {
            type: 'bulletList',
            content: [
                { type: 'listItem', content: [paragraph([{ type: 'text', text: 'عنصر أول' }])] },
                { type: 'listItem', content: [paragraph([{ type: 'text', text: 'عنصر ثانٍ' }])] },
            ],
        },
        {
            type: 'orderedList',
            attrs: { start: 1, type: null },
            content: [
                { type: 'listItem', content: [paragraph([{ type: 'text', text: 'خطوة أولى' }])] },
                { type: 'listItem', content: [paragraph([{ type: 'text', text: 'خطوة ثانية' }])] },
            ],
        },
    ],
};

export const blockquoteDoc: TipTapDoc = {
    type: 'doc',
    content: [
        {
            type: 'blockquote',
            content: [paragraph([{ type: 'text', text: 'اقتباس مهم من دليل الكلية' }])],
        },
    ],
};

export const tableDoc: TipTapDoc = {
    type: 'doc',
    content: [
        {
            type: 'table',
            content: [
                {
                    type: 'tableRow',
                    content: [
                        {
                            type: 'tableHeader',
                            attrs: { colspan: 1, rowspan: 1, colwidth: null },
                            content: [paragraph([{ type: 'text', text: 'المقرر' }])],
                        },
                        {
                            type: 'tableHeader',
                            attrs: { colspan: 1, rowspan: 1, colwidth: null },
                            content: [paragraph([{ type: 'text', text: 'الساعات' }])],
                        },
                    ],
                },
                {
                    type: 'tableRow',
                    content: [
                        {
                            type: 'tableCell',
                            attrs: { colspan: 1, rowspan: 1, colwidth: null, textAlign: null },
                            content: [paragraph([{ type: 'text', text: 'برمجة 1' }])],
                        },
                        {
                            type: 'tableCell',
                            attrs: { colspan: 1, rowspan: 1, colwidth: null, textAlign: null },
                            content: [paragraph([{ type: 'text', text: '3' }])],
                        },
                    ],
                },
            ],
        },
    ],
};

export const codeBlockDoc: TipTapDoc = {
    type: 'doc',
    content: [
        {
            type: 'codeBlock',
            attrs: { language: 'php' },
            content: [{ type: 'text', text: "echo 'مرحبا';" }],
        },
        paragraph([{ type: 'text', text: 'شرح الكود' }]),
    ],
};

export const alertBlockDoc: TipTapDoc = {
    type: 'doc',
    content: [
        paragraph([{ type: 'text', text: 'قبل التنبيه' }]),
        {
            type: 'customBlock',
            attrs: {
                id: 'alert',
                config: {
                    icon: 'solar:info-circle-linear',
                    content: '<p>انتبه إلى <strong>موعد التسجيل</strong> في <a href="https://uqu.edu.sa">البوابة</a></p>',
                },
                label: null,
                preview: null,
            },
        },
    ],
};

export const collapsibleBlockDoc: TipTapDoc = {
    type: 'doc',
    content: [
        {
            type: 'customBlock',
            attrs: {
                id: 'collapsible',
                config: {
                    question: 'كيف أسجل المقررات؟',
                    answer: '<p>من خلال <u>البوابة الإلكترونية</u> ثم اختيار الجدول الدراسي</p>',
                },
                label: 'كيف أسجل المقررات؟',
                preview: null,
            },
        },
        paragraph([{ type: 'text', text: 'بعد القسم القابل للطي' }]),
    ],
};

export const imageAndRuleDoc: TipTapDoc = {
    type: 'doc',
    content: [
        paragraph([
            { type: 'text', text: 'صورة الخطة: ' },
            { type: 'image', attrs: { src: '/storage/uploads/plan.png', alt: null, title: null } },
        ]),
        { type: 'horizontalRule' },
        paragraph([{ type: 'text', text: 'سطر أول' }, { type: 'hardBreak' }, { type: 'text', text: 'سطر ثانٍ' }]),
    ],
};

export const contractFixtures: Array<{ name: string; doc: TipTapDoc }> = [
    { name: 'paragraphs with marks and links', doc: paragraphMarksDoc },
    { name: 'headings with text alignment', doc: headingsDoc },
    { name: 'bullet and ordered lists', doc: listsDoc },
    { name: 'blockquote', doc: blockquoteDoc },
    { name: 'table with header and cells', doc: tableDoc },
    { name: 'code block with language', doc: codeBlockDoc },
    { name: 'alert custom block', doc: alertBlockDoc },
    { name: 'collapsible custom block', doc: collapsibleBlockDoc },
    { name: 'inline image, rule and hard break', doc: imageAndRuleDoc },
];

export const kitchenSinkDoc: TipTapDoc = {
    type: 'doc',
    content: contractFixtures.flatMap(({ doc }) => doc.content ?? []),
};
