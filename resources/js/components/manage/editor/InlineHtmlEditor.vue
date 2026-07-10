<script setup lang="ts">
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { Bold, Check, Italic, Link as LinkIcon, List, ListOrdered, Strikethrough, Underline as UnderlineIcon } from 'lucide-vue-next';
import { ref, watch } from 'vue';

import Input from '@/components/ui/input/Input.vue';

import ToolbarButton from './ToolbarButton.vue';

const props = defineProps<{
    modelValue: string;
    ariaLabel?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

let lastEmittedHtml: string | null = null;

const editor = useEditor({
    content: props.modelValue || '',
    extensions: [
        StarterKit.configure({
            heading: false,
            codeBlock: false,
            blockquote: false,
            horizontalRule: false,
        }),
        Underline,
        Link.configure({
            openOnClick: false,
            autolink: true,
            linkOnPaste: true,
        }),
    ],
    editorProps: {
        attributes: {
            dir: 'rtl',
            class: 'typography min-h-16 max-w-full overflow-x-auto rounded-md border border-input bg-muted/50 px-3 py-2 text-sm focus:outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
            ...(props.ariaLabel ? { 'aria-label': props.ariaLabel } : {}),
        },
    },
    onUpdate: ({ editor: activeEditor }) => {
        const html = activeEditor.isEmpty ? '' : activeEditor.getHTML();
        lastEmittedHtml = html;
        emit('update:modelValue', html);
    },
});

watch(
    () => props.modelValue,
    (value) => {
        if (!editor.value || value === lastEmittedHtml) {
            return;
        }
        editor.value.commands.setContent(value || '');
    },
);

const linkPanelOpen = ref(false);
const linkUrl = ref('');

function toggleLinkPanel(): void {
    if (!editor.value) {
        return;
    }
    if (linkPanelOpen.value) {
        linkPanelOpen.value = false;
        return;
    }
    linkUrl.value = (editor.value.getAttributes('link').href as string | undefined) ?? '';
    linkPanelOpen.value = true;
}

function applyLink(): void {
    if (!editor.value) {
        return;
    }
    const url = linkUrl.value.trim();
    if (url === '') {
        editor.value.chain().focus().extendMarkRange('link').unsetLink().run();
    } else {
        editor.value.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    }
    linkPanelOpen.value = false;
}
</script>

<template>
    <div dir="rtl" class="space-y-1">
        <div class="flex flex-wrap items-center gap-0.5">
            <ToolbarButton
                :icon="Bold"
                title="عريض"
                size="sm"
                :active="editor?.isActive('bold')"
                @click="editor?.chain().focus().toggleBold().run()"
            />
            <ToolbarButton
                :icon="Italic"
                title="مائل"
                size="sm"
                :active="editor?.isActive('italic')"
                @click="editor?.chain().focus().toggleItalic().run()"
            />
            <ToolbarButton
                :icon="UnderlineIcon"
                title="تسطير"
                size="sm"
                :active="editor?.isActive('underline')"
                @click="editor?.chain().focus().toggleUnderline().run()"
            />
            <ToolbarButton
                :icon="Strikethrough"
                title="شطب"
                size="sm"
                :active="editor?.isActive('strike')"
                @click="editor?.chain().focus().toggleStrike().run()"
            />
            <ToolbarButton
                :icon="List"
                title="قائمة نقطية"
                size="sm"
                :active="editor?.isActive('bulletList')"
                @click="editor?.chain().focus().toggleBulletList().run()"
            />
            <ToolbarButton
                :icon="ListOrdered"
                title="قائمة مرقمة"
                size="sm"
                :active="editor?.isActive('orderedList')"
                @click="editor?.chain().focus().toggleOrderedList().run()"
            />
            <ToolbarButton :icon="LinkIcon" title="رابط" size="sm" :active="editor?.isActive('link')" @click="toggleLinkPanel" />
        </div>

        <div v-if="linkPanelOpen" class="flex items-center gap-1">
            <Input v-model="linkUrl" dir="ltr" type="url" placeholder="https://" class="h-7 text-xs" @keydown.enter.prevent="applyLink" />
            <ToolbarButton :icon="Check" title="تطبيق الرابط" size="sm" @click="applyLink" />
        </div>

        <EditorContent :editor="editor" />
    </div>
</template>
