<script setup lang="ts">
import CustomTable from '@/tiptap/extensions/table';
import Image from '@tiptap/extension-image';
import Link from '@tiptap/extension-link';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';
import TableRow from '@tiptap/extension-table-row';
import TextAlign from '@tiptap/extension-text-align';
import Underline from '@tiptap/extension-underline';
import { generateHTML } from '@tiptap/html';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import DOMPurify from 'isomorphic-dompurify';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import AlertBlock from '@/tiptap/extensions/alertBlock';
import CollapsibleBlock from '@/tiptap/extensions/collapsibleBlock';

const props = defineProps<{
    content?: unknown;
}>();

// Track if we're on the client side
const isMounted = ref(false);
const rootElement = ref<HTMLElement | null>(null);
let codeBlocksObserver: MutationObserver | null = null;

const COPY_ICON = '⧉';
const SUCCESS_ICON = '✓';
const ERROR_ICON = '✕';

const updateCopyButtonState = (button: HTMLButtonElement, state: 'default' | 'success' | 'error', timeoutMs = 1800) => {
    button.dataset.state = state;
    button.textContent = state === 'success' ? SUCCESS_ICON : state === 'error' ? ERROR_ICON : COPY_ICON;

    if (state !== 'default') {
        window.setTimeout(() => {
            button.dataset.state = 'default';
            button.textContent = COPY_ICON;
        }, timeoutMs);
    }
};

const addCopyButtons = () => {
    if (!rootElement.value) return;

    const codeBlocks = rootElement.value.querySelectorAll('pre > code');
    for (const codeBlock of codeBlocks) {
        const pre = codeBlock.closest('pre');
        if (!(pre instanceof HTMLPreElement)) continue;
        if (pre.querySelector('.code-copy-button')) continue;

        const copyButton = document.createElement('button');
        copyButton.type = 'button';
        copyButton.className = 'code-copy-button';
        copyButton.dataset.state = 'default';
        copyButton.textContent = COPY_ICON;
        copyButton.setAttribute('aria-label', 'Copy code');
        copyButton.title = 'Copy code';

        copyButton.addEventListener('click', async () => {
            const fullCode = codeBlock.textContent ?? '';

            try {
                await navigator.clipboard.writeText(fullCode);
                updateCopyButtonState(copyButton, 'success');
            } catch {
                updateCopyButtonState(copyButton, 'error');
                toast.error('Failed to copy code block');
            }
        });

        pre.appendChild(copyButton);
    }
};

const initCodeBlockObserver = () => {
    if (!rootElement.value || codeBlocksObserver) return;

    codeBlocksObserver = new MutationObserver(() => {
        addCopyButtons();
    });

    codeBlocksObserver.observe(rootElement.value, {
        childList: true,
        subtree: true,
    });
};

onMounted(() => {
    isMounted.value = true;

    nextTick(() => {
        addCopyButtons();
        initCodeBlockObserver();
    });
});

onBeforeUnmount(() => {
    if (codeBlocksObserver) {
        codeBlocksObserver.disconnect();
        codeBlocksObserver = null;
    }
});

const isJsonContent = computed(() => props.content != null && typeof props.content === 'object' && !Array.isArray(props.content));

const transformCustomBlocks = (node: any): any => {
    if (!node || typeof node !== 'object') return node;

    const nextNode: any = Array.isArray(node) ? [...node] : { ...node };

    if (nextNode.type === 'customBlock') {
        const id = nextNode.attrs?.id;
        if (id === 'alert') {
            nextNode.type = 'alertBlock';
        } else if (id === 'collapsible') {
            nextNode.type = 'collapsibleBlock';
        }
    }

    if (Array.isArray(nextNode.content)) {
        nextNode.content = nextNode.content.map((child: any) => transformCustomBlocks(child));
    }

    return nextNode;
};

const transformedContent = computed(() => {
    if (!isJsonContent.value) return null;
    return transformCustomBlocks(props.content);
});

const cleanHtml = computed(() =>
    typeof props.content === 'string' && props.content
        ? DOMPurify.sanitize(props.content, {
              ALLOWED_TAGS: [
                  'p',
                  'br',
                  'strong',
                  'em',
                  'u',
                  'h1',
                  'h2',
                  'h3',
                  'h4',
                  'h5',
                  'h6',
                  'ul',
                  'ol',
                  'li',
                  'blockquote',
                  'code',
                  'pre',
                  'a',
                  'img',
                  'table',
                  'thead',
                  'tbody',
                  'tr',
                  'th',
                  'td',
              ],
              ALLOWED_ATTR: ['href', 'target', 'rel', 'src', 'alt', 'width', 'height', 'class', 'colspan', 'rowspan', 'scope'],
              ALLOWED_URI_REGEXP: /^(?:(?:(?:f|ht)tps?|mailto|tel|callto|sms|cid|xmpp|data):|[^a-z]|[a-z+.\-]+(?:[^a-z+.\-:]|$))/i,
          })
        : '',
);

// Extensions used for both SSR HTML generation and client-side editor
const extensions = [
    StarterKit.configure({
        heading: {
            levels: [1, 2, 3, 4, 5, 6],
        },
    }),
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

// Generate static HTML for SSR - this works without DOM
const ssrHtml = computed(() => {
    if (!isJsonContent.value || !transformedContent.value) return '';
    try {
        return generateHTML(transformedContent.value as Record<string, any>, extensions);
    } catch {
        return '';
    }
});

// Client-side editor - only created after mount
const editor = useEditor({
    editable: false,
    content: transformedContent.value as Record<string, unknown> | null,
    extensions,
    editorProps: {
        attributes: {
            class: 'typography',
        },
    },
});

watch(
    () => props.content,
    (value) => {
        if (!editor.value) return;

        if (value && typeof value === 'object' && !Array.isArray(value)) {
            editor.value.commands.setContent(transformCustomBlocks(value));
        } else {
            editor.value.commands.clearContent(true);
        }

        nextTick(() => addCopyButtons());
    },
);
</script>

<template>
    <div ref="rootElement">
        <!-- For JSON content: use SSR HTML during SSR, then hydrate with TipTap editor -->
        <template v-if="isJsonContent">
            <!-- Client-side: use the interactive TipTap editor -->
            <EditorContent v-if="isMounted" :editor="editor" />
            <!-- SSR: render static HTML that matches the editor output -->
            <div v-else class="typography" v-html="ssrHtml" />
        </template>
        <!-- For HTML string content -->
        <div v-else class="typography" v-html="cleanHtml" />
    </div>
</template>
