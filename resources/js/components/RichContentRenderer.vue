<script setup lang="ts">
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { generateHTML } from '@tiptap/html';
import Link from '@tiptap/extension-link';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import Underline from '@tiptap/extension-underline';
import Image from '@tiptap/extension-image';
import CustomTable from '@/tiptap/extensions/table';
import TableRow from '@tiptap/extension-table-row';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';
import DOMPurify from 'isomorphic-dompurify';
import { computed, watch, ref, onMounted } from 'vue';

import AlertBlock from '@/tiptap/extensions/alertBlock';
import CollapsibleBlock from '@/tiptap/extensions/collapsibleBlock';
import { HeadingWithId, HeadingWithIdSSR } from '@/tiptap/extensions/heading';

const props = defineProps<{
    content?: unknown;
}>();

// Track if we're on the client side
const isMounted = ref(false);

// Restore scroll position after hydration if there's a hash in the URL
const restoreHashScroll = () => {
    const hash = window.location.hash;
    if (hash) {
        // Use requestAnimationFrame to ensure DOM is fully updated
        requestAnimationFrame(() => {
            const element = document.querySelector(hash);
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }
};

onMounted(() => {
    isMounted.value = true;
    // Restore scroll after a short delay to ensure editor is fully rendered
    setTimeout(restoreHashScroll, 100);
});

const isJsonContent = computed(
    () => props.content != null && typeof props.content === 'object' && !Array.isArray(props.content),
);

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
              ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td'],
              ALLOWED_ATTR: ['href', 'target', 'rel', 'src', 'alt', 'width', 'height', 'class', 'colspan', 'rowspan', 'scope'],
              ALLOWED_URI_REGEXP: /^(?:(?:(?:f|ht)tps?|mailto|tel|callto|sms|cid|xmpp|data):|[^a-z]|[a-z+.\-]+(?:[^a-z+.\-:]|$))/i,
          })
        : '',
);

// Extensions used for SSR HTML generation (with IDs for scroll anchors)
const ssrExtensions = [
    StarterKit.configure({
        heading: false,
    }),
    HeadingWithIdSSR.configure({
        levels: [1, 2, 3, 4, 5, 6],
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

// Extensions for client-side editor with custom heading node view
const extensions = [
    StarterKit.configure({
        heading: false,
    }),
    HeadingWithId.configure({
        levels: [1, 2, 3, 4, 5, 6],
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
        return generateHTML(transformedContent.value as Record<string, any>, ssrExtensions);
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
    },
);
</script>

<template>
    <!-- For JSON content: use SSR HTML during SSR, then hydrate with TipTap editor -->
    <template v-if="isJsonContent">
        <!-- Client-side: use the interactive TipTap editor -->
        <EditorContent v-if="isMounted" :editor="editor" />
        <!-- SSR: render static HTML that matches the editor output -->
        <div v-else class="typography" v-html="ssrHtml" />
    </template>
    <!-- For HTML string content -->
    <div v-else class="typography" v-html="cleanHtml" />
</template>
