import AlertBlock from '@/tiptap/extensions/alertBlock';
import CodeBlock from '@/tiptap/extensions/codeBlock';
import CollapsibleBlock from '@/tiptap/extensions/collapsibleBlock';
import CustomTable from '@/tiptap/extensions/table';
import { getSchema, type JSONContent } from '@tiptap/core';
import Image from '@tiptap/extension-image';
import Link from '@tiptap/extension-link';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';
import TableRow from '@tiptap/extension-table-row';
import TextAlign from '@tiptap/extension-text-align';
import Underline from '@tiptap/extension-underline';
import { generateHTML, generateJSON } from '@tiptap/html';
import { Node as ProseMirrorNode } from '@tiptap/pm/model';
import StarterKit from '@tiptap/starter-kit';
import { describe, expect, it } from 'vitest';

import {
    buildEditorExtensions,
    docHasText,
    editorDocToHtml,
    editorToStoredDoc,
    htmlToEditorDoc,
    MESSAGE_ALLOWED_MARKS,
    MESSAGE_ALLOWED_NODES,
    sanitizeDocForMessage,
    storedToEditorDoc,
    type TipTapNode,
} from './extensions';
import { alertBlockDoc, codeBlockDoc, collapsibleBlockDoc, contractFixtures, headingsDoc, kitchenSinkDoc, paragraphMarksDoc } from './fixtures';

/**
 * The exact extension list RichContentRenderer.vue uses to display stored content.
 * Kept in sync manually (the renderer is a frozen contract) — if this drifts, the
 * schema-compat tests below are the alarm bell.
 */
const rendererExtensions = [
    StarterKit.configure({
        heading: {
            levels: [1, 2, 3, 4, 5, 6],
        },
        codeBlock: false,
    }),
    CodeBlock,
    Underline,
    Link.configure({
        openOnClick: true,
        autolink: true,
        linkOnPaste: true,
    }),
    TextAlign.configure({
        types: ['heading', 'paragraph', 'tableCell'],
    }),
    Image.configure({
        inline: true,
        allowBase64: true,
    }),
    CustomTable.configure({
        resizable: true,
    }),
    TableRow,
    TableHeader,
    TableCell,
    AlertBlock,
    CollapsibleBlock,
];

const editorExtensions = buildEditorExtensions('full');
const messageExtensions = buildEditorExtensions('message');

const editorSchema = getSchema(editorExtensions);
const rendererSchema = getSchema(rendererExtensions);
const messageSchema = getSchema(messageExtensions);

function collectNodeTypes(node: TipTapNode, types = new Set<string>()): Set<string> {
    if (node.type) {
        types.add(node.type);
    }
    for (const child of node.content ?? []) {
        collectNodeTypes(child, types);
    }
    return types;
}

function collectMarkTypes(node: TipTapNode, types = new Set<string>()): Set<string> {
    for (const mark of node.marks ?? []) {
        types.add(mark.type);
    }
    for (const child of node.content ?? []) {
        collectMarkTypes(child, types);
    }
    return types;
}

function collectText(node: TipTapNode): string {
    if (node.type === 'text') {
        return node.text ?? '';
    }
    return (node.content ?? []).map(collectText).join('');
}

describe('stored contract round-trip through the editor schema', () => {
    for (const { name, doc } of contractFixtures) {
        it(`preserves ${name} byte-for-byte`, () => {
            const editorDoc = storedToEditorDoc(doc);
            const parsed = ProseMirrorNode.fromJSON(editorSchema, editorDoc);
            parsed.check();

            expect(editorToStoredDoc(parsed.toJSON() as TipTapNode)).toEqual(doc);
        });
    }

    it('preserves the kitchen-sink document byte-for-byte', () => {
        const parsed = ProseMirrorNode.fromJSON(editorSchema, storedToEditorDoc(kitchenSinkDoc));
        parsed.check();

        expect(editorToStoredDoc(parsed.toJSON() as TipTapNode)).toEqual(kitchenSinkDoc);
    });
});

describe('schema compatibility between editor and public renderer', () => {
    for (const { name, doc } of contractFixtures) {
        it(`renderer schema accepts ${name}`, () => {
            const parsed = ProseMirrorNode.fromJSON(rendererSchema, storedToEditorDoc(doc));

            expect(() => parsed.check()).not.toThrow();
        });
    }

    function hasExplicitAlignment(node: TipTapNode): boolean {
        if (node.attrs?.textAlign != null) {
            return true;
        }
        return (node.content ?? []).some(hasExplicitAlignment);
    }

    /**
     * Custom blocks serialize their `config` as a data attribute (lossy in HTML),
     * and zeed-dom (used by generateJSON server-side) cannot read `style="text-align"`,
     * so those fixtures are only asserted at the JSON level — the actual save path.
     */
    const htmlRoundTrippable = contractFixtures.filter(({ doc }) => !collectNodeTypes(doc).has('customBlock') && !hasExplicitAlignment(doc));

    for (const { name, doc } of htmlRoundTrippable) {
        it(`renderer HTML for ${name} parses back identically with the editor extension set`, () => {
            const editorDoc = storedToEditorDoc(doc);
            const html = generateHTML(editorDoc as unknown as Record<string, unknown>, rendererExtensions);
            const reparsed = generateJSON(html, editorExtensions) as TipTapNode;

            expect(editorToStoredDoc(reparsed)).toEqual(doc);
        });

        it(`editor HTML for ${name} parses back identically with the renderer extension set`, () => {
            const editorDoc = storedToEditorDoc(doc);
            const html = generateHTML(editorDoc as unknown as Record<string, unknown>, editorExtensions);
            const reparsed = generateJSON(html, rendererExtensions) as TipTapNode;

            expect(editorToStoredDoc(reparsed)).toEqual(doc);
        });
    }

    it('custom block HTML keeps its node identity across both extension sets', () => {
        for (const doc of [alertBlockDoc, collapsibleBlockDoc]) {
            const editorDoc = storedToEditorDoc(doc);
            const expectedType = collectNodeTypes(editorDoc).has('alertBlock') ? 'alertBlock' : 'collapsibleBlock';

            const fromRendererHtml = generateJSON(
                generateHTML(editorDoc as unknown as Record<string, unknown>, rendererExtensions),
                editorExtensions,
            );
            const fromEditorHtml = generateJSON(generateHTML(editorDoc as unknown as Record<string, unknown>, editorExtensions), rendererExtensions);

            expect(collectNodeTypes(fromRendererHtml as TipTapNode).has(expectedType)).toBe(true);
            expect(collectNodeTypes(fromEditorHtml as TipTapNode).has(expectedType)).toBe(true);
        }
    });
});

describe('custom block transforms (stored customBlock <-> registered node names)', () => {
    it('maps stored customBlock nodes to the registered extension names', () => {
        const alertTypes = collectNodeTypes(storedToEditorDoc(alertBlockDoc));
        const collapsibleTypes = collectNodeTypes(storedToEditorDoc(collapsibleBlockDoc));

        expect(alertTypes.has('alertBlock')).toBe(true);
        expect(alertTypes.has('customBlock')).toBe(false);
        expect(collapsibleTypes.has('collapsibleBlock')).toBe(true);
        expect(collapsibleTypes.has('customBlock')).toBe(false);
    });

    it('is a lossless inverse pair', () => {
        expect(editorToStoredDoc(storedToEditorDoc(alertBlockDoc))).toEqual(alertBlockDoc);
        expect(editorToStoredDoc(storedToEditorDoc(collapsibleBlockDoc))).toEqual(collapsibleBlockDoc);
    });

    it('does not mutate the input document', () => {
        const original = JSON.parse(JSON.stringify(alertBlockDoc));
        storedToEditorDoc(alertBlockDoc);

        expect(alertBlockDoc).toEqual(original);
    });
});

describe('message variant restricted schema', () => {
    it('sanitizes any document down to the allowed node and mark set', () => {
        const sanitized = sanitizeDocForMessage(storedToEditorDoc(kitchenSinkDoc));

        for (const type of collectNodeTypes(sanitized)) {
            expect(MESSAGE_ALLOWED_NODES.has(type)).toBe(true);
        }
        for (const mark of collectMarkTypes(sanitized)) {
            expect(MESSAGE_ALLOWED_MARKS.has(mark)).toBe(true);
        }

        const parsed = ProseMirrorNode.fromJSON(messageSchema, sanitized);
        expect(() => parsed.check()).not.toThrow();
    });

    it('downgrades headings to paragraphs while keeping their text', () => {
        const sanitized = sanitizeDocForMessage(storedToEditorDoc(headingsDoc));

        expect(collectNodeTypes(sanitized).has('heading')).toBe(false);
        expect(collectText(sanitized)).toContain('عنوان ثانٍ');
        expect(collectText(sanitized)).toContain('عنوان رابع موسّط');
    });

    it('keeps allowed marks and strips disallowed ones', () => {
        const sanitized = sanitizeDocForMessage(storedToEditorDoc(paragraphMarksDoc));
        const marks = collectMarkTypes(sanitized);

        expect(marks.has('bold')).toBe(true);
        expect(marks.has('underline')).toBe(true);
        expect(marks.has('link')).toBe(true);
        expect(marks.has('code')).toBe(false);
        expect(collectText(sanitized)).toContain('كود مضمّن');
    });

    it('preserves code blocks with their language attribute', () => {
        const sanitized = sanitizeDocForMessage(storedToEditorDoc(codeBlockDoc));
        const codeBlock = sanitized.content?.find((node) => node.type === 'codeBlock');

        expect(codeBlock).toBeDefined();
        expect(codeBlock?.attrs?.language).toBe('php');
        expect(collectText(codeBlock!)).toBe("echo 'مرحبا';");
    });

    it('drops custom blocks, images and rules instead of crashing', () => {
        const sanitized = sanitizeDocForMessage(storedToEditorDoc(alertBlockDoc));
        const types = collectNodeTypes(sanitized);

        expect(types.has('alertBlock')).toBe(false);
        expect(types.has('customBlock')).toBe(false);
        expect(collectText(sanitized)).toBe('قبل التنبيه');
    });

    it('falls back to a single empty paragraph for content-free documents', () => {
        const sanitized = sanitizeDocForMessage({ type: 'doc', content: [{ type: 'horizontalRule' }] });

        expect(sanitized).toEqual({ type: 'doc', content: [{ type: 'paragraph' }] });
        expect(() => ProseMirrorNode.fromJSON(messageSchema, sanitized).check()).not.toThrow();
    });

    it('message schema itself refuses nodes outside the allowed set', () => {
        expect(() => ProseMirrorNode.fromJSON(messageSchema, storedToEditorDoc(alertBlockDoc) as JSONContent)).toThrow();
    });
});

describe('message HTML format (frozen quick_response_message contract)', () => {
    /**
     * The bot's `UquccSearchHandler::cleanHtmlContent()` converts <p>/<br> to
     * newlines and then keeps ONLY <b><strong><i><em><u><s><strike><del><code><pre><a>
     * via strip_tags. Everything the editor emits must stay inside that set —
     * exactly the tags Filament's RichEditor produced for the message toolbar.
     */
    const botSafeTags = new Set(['p', 'br', 'strong', 'em', 'u', 's', 'a', 'pre', 'code']);

    const markupFixtures: Array<{ name: string; html: string }> = [
        { name: 'bold', html: '<p><strong>عريض</strong></p>' },
        { name: 'italic', html: '<p><em>مائل</em></p>' },
        { name: 'underline', html: '<p><u>تسطير</u></p>' },
        { name: 'strike', html: '<p><s>شطب</s></p>' },
        { name: 'link', html: '<p><a href="https://example.com">رابط</a></p>' },
        { name: 'codeBlock', html: '<pre><code>echo 1;</code></pre>' },
        { name: 'hard break', html: '<p>سطر أول<br>سطر ثانٍ</p>' },
        {
            name: 'combined marks and paragraphs',
            html: '<p><strong>رد </strong><a href="https://example.com">سريع</a></p><p><s>قديم</s> <u>جديد</u></p>',
        },
    ];

    for (const { name, html } of markupFixtures) {
        it(`round-trips ${name} HTML byte-identically`, () => {
            const doc = htmlToEditorDoc(html, messageExtensions, 'message');

            expect(editorDocToHtml(doc, messageExtensions)).toBe(html);
        });
    }

    it('emits only tags the bot cleanHtmlContent allowlist understands', () => {
        const doc = htmlToEditorDoc(markupFixtures.map(({ html }) => html).join(''), messageExtensions, 'message');
        const html = editorDocToHtml(doc, messageExtensions)!;

        for (const [, tag] of html.matchAll(/<\/?([a-z0-9]+)/gi)) {
            expect(botSafeTags.has(tag.toLowerCase()), `unexpected tag <${tag}>`).toBe(true);
        }
    });

    it('emits bare <a href> links without target/rel, the only shape Telegram parses', () => {
        const doc = htmlToEditorDoc('<p><a href="https://example.com" target="_blank" rel="noopener">رابط</a></p>', messageExtensions, 'message');
        const html = editorDocToHtml(doc, messageExtensions)!;

        expect(html).toContain('<a href="https://example.com">');
        expect(html).not.toContain('target=');
        expect(html).not.toContain('rel=');
    });

    it('normalizes legacy <b>/<i> input to <strong>/<em>, which the bot maps back to <b>/<i>', () => {
        const doc = htmlToEditorDoc('<p><b>عريض</b> و<i>مائل</i></p>', messageExtensions, 'message');

        expect(editorDocToHtml(doc, messageExtensions)).toBe('<p><strong>عريض</strong> و<em>مائل</em></p>');
    });

    it('reduces disallowed structure (headings, images) to plain paragraphs with the text kept', () => {
        const doc = htmlToEditorDoc('<h2>عنوان</h2><p>نص <img src="x.png"> متبقٍ</p>', messageExtensions, 'message');
        const html = editorDocToHtml(doc, messageExtensions)!;

        expect(html).toContain('عنوان');
        expect(html).toContain('نص');
        expect(html).not.toContain('<h2');
        expect(html).not.toContain('<img');
    });

    it('serializes documents without text to null (the bot has nothing to say)', () => {
        expect(editorDocToHtml({ type: 'doc', content: [{ type: 'paragraph' }] }, messageExtensions)).toBeNull();
        expect(editorDocToHtml(null, messageExtensions)).toBeNull();
        expect(docHasText({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: ' ' }] }] })).toBe(false);
        expect(docHasText({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'رد' }] }] })).toBe(true);
    });
});
