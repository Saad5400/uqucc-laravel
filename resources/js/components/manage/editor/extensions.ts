import AlertBlock from '@/tiptap/extensions/alertBlock';
import CodeBlock from '@/tiptap/extensions/codeBlock';
import CollapsibleBlock from '@/tiptap/extensions/collapsibleBlock';
import CustomTable from '@/tiptap/extensions/table';
import type { AnyExtension, JSONContent } from '@tiptap/core';
import Image from '@tiptap/extension-image';
import Link from '@tiptap/extension-link';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';
import TableRow from '@tiptap/extension-table-row';
import TextAlign from '@tiptap/extension-text-align';
import Underline from '@tiptap/extension-underline';
import { generateHTML, generateJSON } from '@tiptap/html';
import StarterKit from '@tiptap/starter-kit';
import { VueNodeViewRenderer } from '@tiptap/vue-3';

import AlertBlockEditorView from './views/AlertBlockEditorView.vue';
import CollapsibleBlockEditorView from './views/CollapsibleBlockEditorView.vue';

export type EditorVariant = 'full' | 'message';

export interface TipTapMark {
    type: string;
    attrs?: Record<string, unknown>;
}

export interface TipTapNode {
    type?: string;
    attrs?: Record<string, unknown>;
    marks?: TipTapMark[];
    content?: TipTapNode[];
    text?: string;
}

export type TipTapDoc = TipTapNode & { type: 'doc' };

/**
 * Stored contract (Filament / database format): custom blocks are persisted as
 * `{ type: 'customBlock', attrs: { id: 'alert' | 'collapsible', config, label, preview } }`.
 * The registered TipTap extensions use the node names `alertBlock` / `collapsibleBlock`,
 * so documents are transformed on the way in and back out — exactly mirroring
 * `RichContentRenderer.vue`'s `transformCustomBlocks`.
 */
const CUSTOM_BLOCK_NODE_NAMES: Record<string, string> = {
    alert: 'alertBlock',
    collapsible: 'collapsibleBlock',
};

const EDITOR_CUSTOM_BLOCK_NAMES = new Set(Object.values(CUSTOM_BLOCK_NODE_NAMES));

export function storedToEditorDoc<T extends TipTapNode>(node: T): T {
    if (!node || typeof node !== 'object') {
        return node;
    }

    const nextNode: TipTapNode = { ...node };

    if (nextNode.type === 'customBlock') {
        const id = nextNode.attrs?.id;
        if (typeof id === 'string' && CUSTOM_BLOCK_NODE_NAMES[id]) {
            nextNode.type = CUSTOM_BLOCK_NODE_NAMES[id];
        }
    }

    if (Array.isArray(nextNode.content)) {
        nextNode.content = nextNode.content.map((child) => storedToEditorDoc(child));
    }

    return nextNode as T;
}

export function editorToStoredDoc<T extends TipTapNode>(node: T): T {
    if (!node || typeof node !== 'object') {
        return node;
    }

    const nextNode: TipTapNode = { ...node };

    if (typeof nextNode.type === 'string' && EDITOR_CUSTOM_BLOCK_NAMES.has(nextNode.type)) {
        nextNode.type = 'customBlock';
    }

    if (Array.isArray(nextNode.content)) {
        nextNode.content = nextNode.content.map((child) => editorToStoredDoc(child));
    }

    return nextNode as T;
}

const EditableAlertBlock = AlertBlock.extend({
    addNodeView() {
        return VueNodeViewRenderer(AlertBlockEditorView);
    },
});

const EditableCollapsibleBlock = CollapsibleBlock.extend({
    addNodeView() {
        return VueNodeViewRenderer(CollapsibleBlockEditorView);
    },
});

/**
 * The 'full' set mirrors the extension list in RichContentRenderer.vue so the editor
 * schema is byte-compatible with the public renderer. Only editor-behaviour options
 * differ (link clicks disabled while editing, editable node views for custom blocks).
 */
export function buildEditorExtensions(variant: EditorVariant): AnyExtension[] {
    if (variant === 'message') {
        return [
            StarterKit.configure({
                heading: false,
                blockquote: false,
                bulletList: false,
                orderedList: false,
                listItem: false,
                horizontalRule: false,
                code: false,
                codeBlock: false,
            }),
            CodeBlock,
            Underline,
            Link.configure({
                openOnClick: false,
                autolink: true,
                linkOnPaste: true,
                /*
                 * The message serializes back to HTML consumed by the Telegram
                 * bot; Telegram's HTML parse mode only supports a bare
                 * `<a href>`, so the default target/rel attributes are dropped.
                 */
                HTMLAttributes: {
                    target: null,
                    rel: null,
                },
            }),
        ];
    }

    return [
        StarterKit.configure({
            heading: {
                levels: [1, 2, 3, 4, 5, 6],
            },
            codeBlock: false,
        }),
        CodeBlock,
        Underline,
        Link.configure({
            openOnClick: false,
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
        EditableAlertBlock,
        EditableCollapsibleBlock,
    ];
}

export const MESSAGE_ALLOWED_NODES = new Set(['doc', 'paragraph', 'text', 'hardBreak', 'codeBlock']);
export const MESSAGE_ALLOWED_MARKS = new Set(['bold', 'italic', 'strike', 'underline', 'link']);

function sanitizeInlineForMessage(nodes: TipTapNode[]): TipTapNode[] {
    const result: TipTapNode[] = [];

    for (const node of nodes) {
        if (node.type === 'text') {
            const marks = (node.marks ?? [])
                .filter((mark) => MESSAGE_ALLOWED_MARKS.has(mark.type))
                .map((mark) => (mark.type === 'link' ? { type: 'link', attrs: { href: mark.attrs?.href ?? null } } : mark));
            const textNode: TipTapNode = { type: 'text', text: node.text };
            if (marks.length > 0) {
                textNode.marks = marks;
            }
            result.push(textNode);
        } else if (node.type === 'hardBreak') {
            result.push({ type: 'hardBreak' });
        }
    }

    return result;
}

function extractPlainText(nodes: TipTapNode[]): TipTapNode[] {
    const result: TipTapNode[] = [];

    for (const node of nodes) {
        if (node.type === 'text' && node.text) {
            result.push({ type: 'text', text: node.text });
        } else if (Array.isArray(node.content)) {
            result.push(...extractPlainText(node.content));
        }
    }

    return result;
}

function flattenBlocksForMessage(nodes: TipTapNode[]): TipTapNode[] {
    const result: TipTapNode[] = [];

    for (const node of nodes) {
        switch (node.type) {
            case 'paragraph':
            case 'heading': {
                const content = sanitizeInlineForMessage(node.content ?? []);
                const paragraph: TipTapNode = { type: 'paragraph' };
                if (content.length > 0) {
                    paragraph.content = content;
                }
                result.push(paragraph);
                break;
            }

            case 'codeBlock': {
                const codeBlock: TipTapNode = {
                    type: 'codeBlock',
                    attrs: { language: (node.attrs?.language as string | null) ?? null },
                };
                const content = extractPlainText(node.content ?? []);
                if (content.length > 0) {
                    codeBlock.content = content;
                }
                result.push(codeBlock);
                break;
            }

            case 'alertBlock':
            case 'collapsibleBlock':
            case 'customBlock':
            case 'image':
            case 'horizontalRule':
                break;

            default:
                if (Array.isArray(node.content)) {
                    result.push(...flattenBlocksForMessage(node.content));
                }
                break;
        }
    }

    return result;
}

/**
 * Reduce any document to the restricted 'message' schema (mirrors the Filament
 * quick_response_message toolbar: bold/italic/underline/strike/link + codeBlock).
 * Disallowed blocks are downgraded to paragraphs where they carry text, or
 * dropped when they cannot be represented (images, custom blocks, rules).
 * Link marks keep only `href` — the sole attribute Telegram's HTML parse
 * mode supports on `<a>`.
 */
export function sanitizeDocForMessage(doc: TipTapNode | null | undefined): TipTapDoc {
    const blocks = flattenBlocksForMessage(doc?.content ?? []);

    return {
        type: 'doc',
        content: blocks.length > 0 ? blocks : [{ type: 'paragraph' }],
    };
}

/** True when the document contains at least one non-whitespace text node. */
export function docHasText(node: TipTapNode | null | undefined): boolean {
    if (!node || typeof node !== 'object') {
        return false;
    }
    if (typeof node.text === 'string' && node.text.trim() !== '') {
        return true;
    }

    return Array.isArray(node.content) && node.content.some((child) => docHasText(child));
}

/**
 * Parse an HTML string (the format Filament's RichEditor stored for
 * `quick_response_message`) into an editor document. Message-variant
 * documents are additionally reduced to the restricted message schema.
 */
export function htmlToEditorDoc(html: string, extensions: AnyExtension[], variant: EditorVariant): TipTapDoc {
    const doc = generateJSON(html, extensions) as TipTapDoc;

    return variant === 'message' ? sanitizeDocForMessage(doc) : doc;
}

/**
 * Serialize an editor document back to the HTML string format Filament's
 * RichEditor produced — the format the Telegram bot (`cleanHtmlContent`),
 * `Seo::descriptionFor()` and `QuickResponseService` consume. Documents
 * without any text serialize to `null` (the bot's "nothing to say").
 */
export function editorDocToHtml(doc: TipTapNode | null | undefined, extensions: AnyExtension[]): string | null {
    if (!docHasText(doc)) {
        return null;
    }

    return generateHTML(doc as JSONContent, extensions);
}
