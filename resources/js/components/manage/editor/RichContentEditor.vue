<script setup lang="ts">
import type { JSONContent } from '@tiptap/core';
import { generateJSON } from '@tiptap/html';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import DOMPurify from 'isomorphic-dompurify';
import {
    AlignCenter,
    AlignJustify,
    AlignLeft,
    AlignRight,
    Bold,
    Check,
    ChevronDown,
    ChevronsUpDown,
    Code2,
    Ellipsis,
    Heading2,
    Heading3,
    Heading4,
    Image as ImageIcon,
    Italic,
    Link as LinkIcon,
    List,
    ListOrdered,
    Loader2,
    Minus,
    Pilcrow,
    Plus,
    Redo2,
    Strikethrough,
    Table as TableIcon,
    TextQuote,
    TriangleAlert,
    Underline as UnderlineIcon,
    Undo2,
    Unlink,
} from 'lucide-vue-next';
import { computed, ref, watch, type Component } from 'vue';

import Alert from '@/components/ui/alert/Alert.vue';
import AlertDescription from '@/components/ui/alert/AlertDescription.vue';
import Button from '@/components/ui/button/Button.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import Input from '@/components/ui/input/Input.vue';

import {
    buildEditorExtensions,
    editorDocToHtml,
    editorToStoredDoc,
    htmlToEditorDoc,
    sanitizeDocForMessage,
    storedToEditorDoc,
    type EditorVariant,
} from './extensions';
import ToolbarButton from './ToolbarButton.vue';

/**
 * Persistence format:
 * - 'json' (default): the model value is a stored-format TipTap document
 *   (`html_content`'s frozen contract); plain HTML strings are treated as
 *   legacy content shown read-only behind an explicit convert button.
 * - 'html': the model value is an HTML string (`quick_response_message`'s
 *   format, consumed by the Telegram bot); strings are parsed straight into
 *   the editor and updates emit HTML back — `null` for an empty document.
 */
type EditorFormat = 'json' | 'html';

const props = withDefaults(
    defineProps<{
        modelValue: Record<string, unknown> | string | null;
        variant?: EditorVariant;
        format?: EditorFormat;
        uploadUrl?: string;
    }>(),
    {
        variant: 'full',
        format: 'json',
        uploadUrl: undefined,
    },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: Record<string, unknown> | string | null): void;
}>();

/** Mirrors the DOMPurify config RichContentRenderer.vue applies to legacy HTML strings. */
function sanitizeLegacyHtml(html: string): string {
    return DOMPurify.sanitize(html, {
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
    });
}

const legacyConverted = ref(false);
const isLegacyHtml = computed(
    () => props.format === 'json' && typeof props.modelValue === 'string' && props.modelValue.trim() !== '' && !legacyConverted.value,
);
const legacyPreviewHtml = computed(() => (typeof props.modelValue === 'string' ? sanitizeLegacyHtml(props.modelValue) : ''));

const extensions = buildEditorExtensions(props.variant);

function toEditorContent(value: Record<string, unknown> | string | null): JSONContent | null {
    if (props.format === 'html') {
        if (typeof value !== 'string' || value.trim() === '') {
            return null;
        }
        return htmlToEditorDoc(sanitizeLegacyHtml(value), extensions, props.variant) as JSONContent;
    }
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return null;
    }
    const doc = storedToEditorDoc(value as JSONContent);
    return props.variant === 'message' ? (sanitizeDocForMessage(doc) as JSONContent) : doc;
}

/** The last emitted value, serialized: JSON for 'json' format, the HTML string itself for 'html'. */
let lastEmittedValue: string | null = null;

function emitFromEditor(activeEditor: { getJSON: () => JSONContent }): void {
    if (props.format === 'html') {
        const html = editorDocToHtml(activeEditor.getJSON(), extensions);
        lastEmittedValue = html;
        emit('update:modelValue', html);
        return;
    }
    const stored = editorToStoredDoc(activeEditor.getJSON());
    lastEmittedValue = JSON.stringify(stored);
    emit('update:modelValue', stored as Record<string, unknown>);
}

const editor = useEditor({
    content: toEditorContent(props.modelValue),
    extensions,
    editorProps: {
        attributes: {
            dir: 'rtl',
            class: 'typography min-h-64 px-4 py-3 focus:outline-none',
        },
        handlePaste: (_view, event) => handleImagePaste(event),
        handleDrop: (view, event, _slice, moved) => handleImageDrop(view, event, moved),
    },
    onUpdate: ({ editor: activeEditor }) => emitFromEditor(activeEditor),
});

watch(
    () => props.modelValue,
    (value) => {
        if (!editor.value || (props.format === 'json' && typeof value === 'string')) {
            return;
        }
        const serialized = props.format === 'html' ? ((value as string | null) ?? null) : JSON.stringify(value ?? null);
        if (serialized === lastEmittedValue) {
            return;
        }
        const content = toEditorContent(value);
        if (content) {
            editor.value.commands.setContent(content);
        } else {
            editor.value.commands.clearContent(true);
        }
    },
);

function convertLegacyHtml(): void {
    if (!editor.value || typeof props.modelValue !== 'string') {
        return;
    }
    const json = generateJSON(sanitizeLegacyHtml(props.modelValue), extensions) as JSONContent;
    const content = props.variant === 'message' ? (sanitizeDocForMessage(json) as JSONContent) : json;
    legacyConverted.value = true;
    editor.value.commands.setContent(content);
    emitFromEditor(editor.value);
}

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

const uploading = ref(false);
const uploadError = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

function readXsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function uploadImage(file: File): Promise<string | null> {
    if (!props.uploadUrl) {
        return null;
    }
    uploading.value = true;
    uploadError.value = null;
    try {
        const formData = new FormData();
        formData.append('file', file);
        const response = await fetch(props.uploadUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
            },
        });
        if (!response.ok) {
            throw new Error(`Upload failed with status ${response.status}`);
        }
        const payload = (await response.json()) as { url?: string };
        if (!payload.url) {
            throw new Error('Upload response missing url');
        }
        return payload.url;
    } catch {
        uploadError.value = 'تعذّر رفع الصورة، حاول مرة أخرى';
        return null;
    } finally {
        uploading.value = false;
    }
}

async function insertImageFromFile(file: File, position?: number): Promise<void> {
    const url = await uploadImage(file);
    if (!url || !editor.value) {
        return;
    }
    if (position !== undefined) {
        editor.value
            .chain()
            .focus()
            .insertContentAt(position, { type: 'image', attrs: { src: url } })
            .run();
    } else {
        editor.value.chain().focus().setImage({ src: url }).run();
    }
}

function onFileInputChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (file) {
        void insertImageFromFile(file);
    }
    input.value = '';
}

function handleImagePaste(event: ClipboardEvent): boolean {
    if (!props.uploadUrl || props.variant !== 'full') {
        return false;
    }
    const imageFile = Array.from(event.clipboardData?.files ?? []).find((file) => file.type.startsWith('image/'));
    if (!imageFile) {
        return false;
    }
    void insertImageFromFile(imageFile);
    return true;
}

function handleImageDrop(
    view: { posAtCoords: (coords: { left: number; top: number }) => { pos: number } | null },
    event: DragEvent,
    moved: boolean,
): boolean {
    if (!props.uploadUrl || props.variant !== 'full' || moved) {
        return false;
    }
    const imageFile = Array.from(event.dataTransfer?.files ?? []).find((file) => file.type.startsWith('image/'));
    if (!imageFile) {
        return false;
    }
    const coordinates = view.posAtCoords({ left: event.clientX, top: event.clientY });
    void insertImageFromFile(imageFile, coordinates?.pos);
    return true;
}

const codeBlockLanguage = computed(() => (editor.value?.getAttributes('codeBlock').language as string | null) ?? '');

function setCodeBlockLanguage(value: string | number): void {
    const language = String(value).trim();
    editor.value
        ?.chain()
        .focus()
        .updateAttributes('codeBlock', { language: language === '' ? null : language })
        .run();
}

function insertAlertBlock(): void {
    editor.value
        ?.chain()
        .focus()
        .insertContent({
            type: 'alertBlock',
            attrs: {
                id: 'alert',
                config: { icon: 'solar:info-circle-linear', content: '' },
            },
        })
        .run();
}

function insertCollapsibleBlock(): void {
    editor.value
        ?.chain()
        .focus()
        .insertContent({
            type: 'collapsibleBlock',
            attrs: {
                id: 'collapsible',
                config: { question: '', answer: '' },
            },
        })
        .run();
}

type Alignment = 'right' | 'center' | 'left' | 'justify';

function toggleTextAlign(alignment: Alignment): void {
    if (!editor.value) {
        return;
    }
    if (editor.value.isActive({ textAlign: alignment })) {
        editor.value.chain().focus().unsetTextAlign().run();
    } else {
        editor.value.chain().focus().setTextAlign(alignment).run();
    }
}

const blockTypes = [
    { label: 'فقرة', icon: Pilcrow, level: null },
    { label: 'عنوان 2', icon: Heading2, level: 2 },
    { label: 'عنوان 3', icon: Heading3, level: 3 },
    { label: 'عنوان 4', icon: Heading4, level: 4 },
] as const;

const activeBlockType = computed(() => {
    for (const blockType of blockTypes) {
        if (blockType.level !== null && editor.value?.isActive('heading', { level: blockType.level })) {
            return blockType;
        }
    }
    return blockTypes[0];
});

function setBlockType(level: 2 | 3 | 4 | null): void {
    if (!editor.value) {
        return;
    }
    if (level === null) {
        editor.value.chain().focus().setParagraph().run();
    } else {
        editor.value.chain().focus().toggleHeading({ level }).run();
    }
}

const alignments: { label: string; icon: Component; value: Alignment }[] = [
    { label: 'محاذاة لليمين', icon: AlignRight, value: 'right' },
    { label: 'توسيط', icon: AlignCenter, value: 'center' },
    { label: 'محاذاة لليسار', icon: AlignLeft, value: 'left' },
    { label: 'ضبط', icon: AlignJustify, value: 'justify' },
];

/** Shared look for the dropdown trigger buttons so they match ToolbarButton (sizing applied per-trigger). */
const triggerBaseClass =
    'inline-flex shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors ' +
    'hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';
const triggerClass = `${triggerBaseClass} size-8 pointer-coarse:size-11`;
</script>

<template>
    <div dir="rtl">
        <div v-if="isLegacyHtml" class="space-y-3">
            <Alert>
                <TriangleAlert class="size-4" />
                <AlertDescription>
                    هذا المحتوى محفوظ بصيغة HTML القديمة ويُعرض للقراءة فقط. حوّله إلى المحرر الجديد لتتمكن من تعديله.
                </AlertDescription>
            </Alert>
            <!-- eslint-disable-next-line vue/no-v-html — sanitized with the same DOMPurify config as the public renderer -->
            <div class="typography max-h-96 overflow-y-auto rounded-md border p-4" v-html="legacyPreviewHtml" />
            <Button type="button" variant="secondary" @click="convertLegacyHtml">التحويل إلى المحرر الجديد</Button>
        </div>

        <div v-else class="relative rounded-md border border-input">
            <div
                class="sticky top-17 z-10 flex items-center gap-0.5 overflow-x-auto rounded-t-md border-b border-input bg-card/95 p-1 backdrop-blur md:flex-wrap md:overflow-x-visible"
            >
                <ToolbarButton :icon="Undo2" title="تراجع" :disabled="!editor?.can().undo()" @click="editor?.chain().focus().undo().run()" />
                <ToolbarButton :icon="Redo2" title="إعادة" :disabled="!editor?.can().redo()" @click="editor?.chain().focus().redo().run()" />

                <div class="mx-1 h-5 w-px shrink-0 bg-border" />

                <template v-if="variant === 'full'">
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                title="نوع الفقرة"
                                aria-label="نوع الفقرة"
                                :class="[triggerBaseClass, 'h-8 gap-1 px-2 text-sm whitespace-nowrap pointer-coarse:h-11']"
                                @mousedown.prevent
                            >
                                <component :is="activeBlockType.icon" class="size-4" />
                                <span class="hidden sm:inline">{{ activeBlockType.label }}</span>
                                <ChevronDown class="size-3.5 opacity-60" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuItem v-for="blockType in blockTypes" :key="blockType.label" @select="setBlockType(blockType.level)">
                                <component :is="blockType.icon" class="size-4" />
                                {{ blockType.label }}
                                <Check v-if="activeBlockType.label === blockType.label" class="ms-auto size-4" />
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <div class="mx-1 h-5 w-px shrink-0 bg-border" />
                </template>

                <ToolbarButton :icon="Bold" title="عريض" :active="editor?.isActive('bold')" @click="editor?.chain().focus().toggleBold().run()" />
                <ToolbarButton
                    :icon="Italic"
                    title="مائل"
                    :active="editor?.isActive('italic')"
                    @click="editor?.chain().focus().toggleItalic().run()"
                />
                <ToolbarButton :icon="LinkIcon" title="رابط" :active="editor?.isActive('link')" @click="toggleLinkPanel" />
                <ToolbarButton
                    v-if="editor?.isActive('link')"
                    :icon="Unlink"
                    title="إزالة الرابط"
                    @click="editor?.chain().focus().extendMarkRange('link').unsetLink().run()"
                />

                <template v-if="variant === 'message'">
                    <ToolbarButton
                        :icon="UnderlineIcon"
                        title="تسطير"
                        :active="editor?.isActive('underline')"
                        @click="editor?.chain().focus().toggleUnderline().run()"
                    />
                    <ToolbarButton
                        :icon="Strikethrough"
                        title="شطب"
                        :active="editor?.isActive('strike')"
                        @click="editor?.chain().focus().toggleStrike().run()"
                    />

                    <div class="mx-1 h-5 w-px shrink-0 bg-border" />

                    <ToolbarButton
                        :icon="Code2"
                        title="كتلة كود"
                        :active="editor?.isActive('codeBlock')"
                        @click="editor?.chain().focus().toggleCodeBlock().run()"
                    />
                </template>

                <template v-if="variant === 'full'">
                    <div class="mx-1 h-5 w-px shrink-0 bg-border" />

                    <ToolbarButton
                        :icon="List"
                        title="قائمة نقطية"
                        :active="editor?.isActive('bulletList')"
                        @click="editor?.chain().focus().toggleBulletList().run()"
                    />
                    <ToolbarButton
                        :icon="ListOrdered"
                        title="قائمة مرقمة"
                        :active="editor?.isActive('orderedList')"
                        @click="editor?.chain().focus().toggleOrderedList().run()"
                    />
                    <ToolbarButton
                        :icon="TextQuote"
                        title="اقتباس"
                        :active="editor?.isActive('blockquote')"
                        @click="editor?.chain().focus().toggleBlockquote().run()"
                    />

                    <div class="mx-1 h-5 w-px shrink-0 bg-border" />

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button type="button" title="إدراج" aria-label="إدراج" :class="triggerClass" @mousedown.prevent>
                                <Plus class="size-4 pointer-coarse:size-5" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuLabel>إدراج</DropdownMenuLabel>
                            <DropdownMenuItem v-if="uploadUrl" :disabled="uploading" @select="fileInput?.click()">
                                <component :is="uploading ? Loader2 : ImageIcon" class="size-4" :class="uploading && 'animate-spin'" />
                                صورة
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="editor?.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()">
                                <TableIcon class="size-4" />
                                جدول
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="insertAlertBlock">
                                <TriangleAlert class="size-4" />
                                كتلة تنبيه
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="insertCollapsibleBlock">
                                <ChevronsUpDown class="size-4" />
                                قسم قابل للطي
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="editor?.chain().focus().toggleCodeBlock().run()">
                                <Code2 class="size-4" />
                                كتلة كود
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="editor?.chain().focus().setHorizontalRule().run()">
                                <Minus class="size-4" />
                                خط فاصل
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <DropdownMenu v-if="editor?.isActive('table')">
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                title="جدول"
                                aria-label="جدول"
                                :class="[triggerClass, 'bg-accent text-accent-foreground']"
                                @mousedown.prevent
                            >
                                <TableIcon class="size-4 pointer-coarse:size-5" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuLabel>الجدول</DropdownMenuLabel>
                            <DropdownMenuItem :disabled="!editor?.can().addRowBefore()" @select="editor?.chain().focus().addRowBefore().run()">
                                إضافة صف قبل
                            </DropdownMenuItem>
                            <DropdownMenuItem :disabled="!editor?.can().addRowAfter()" @select="editor?.chain().focus().addRowAfter().run()">
                                إضافة صف بعد
                            </DropdownMenuItem>
                            <DropdownMenuItem :disabled="!editor?.can().deleteRow()" @select="editor?.chain().focus().deleteRow().run()">
                                حذف الصف
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem :disabled="!editor?.can().addColumnBefore()" @select="editor?.chain().focus().addColumnBefore().run()">
                                إضافة عمود قبل
                            </DropdownMenuItem>
                            <DropdownMenuItem :disabled="!editor?.can().addColumnAfter()" @select="editor?.chain().focus().addColumnAfter().run()">
                                إضافة عمود بعد
                            </DropdownMenuItem>
                            <DropdownMenuItem :disabled="!editor?.can().deleteColumn()" @select="editor?.chain().focus().deleteColumn().run()">
                                حذف العمود
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem :disabled="!editor?.can().toggleHeaderRow()" @select="editor?.chain().focus().toggleHeaderRow().run()">
                                تبديل صف الرؤوس
                            </DropdownMenuItem>
                            <DropdownMenuItem :disabled="!editor?.can().deleteTable()" @select="editor?.chain().focus().deleteTable().run()">
                                حذف الجدول
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button type="button" title="تنسيقات إضافية" aria-label="تنسيقات إضافية" :class="triggerClass" @mousedown.prevent>
                                <Ellipsis class="size-4 pointer-coarse:size-5" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuItem @select="editor?.chain().focus().toggleUnderline().run()">
                                <UnderlineIcon class="size-4" />
                                تسطير
                                <Check v-if="editor?.isActive('underline')" class="ms-auto size-4" />
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="editor?.chain().focus().toggleStrike().run()">
                                <Strikethrough class="size-4" />
                                شطب
                                <Check v-if="editor?.isActive('strike')" class="ms-auto size-4" />
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem v-for="alignment in alignments" :key="alignment.value" @select="toggleTextAlign(alignment.value)">
                                <component :is="alignment.icon" class="size-4" />
                                {{ alignment.label }}
                                <Check v-if="editor?.isActive({ textAlign: alignment.value })" class="ms-auto size-4" />
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>

                <Input
                    v-if="editor?.isActive('codeBlock')"
                    :model-value="codeBlockLanguage"
                    dir="ltr"
                    placeholder="lang"
                    title="لغة الكود"
                    aria-label="لغة الكود"
                    class="h-7 w-24 shrink-0 font-mono text-xs"
                    @change="setCodeBlockLanguage(($event.target as HTMLInputElement).value)"
                />
            </div>

            <div v-if="linkPanelOpen" class="flex items-center gap-1 border-b p-1">
                <Input
                    v-model="linkUrl"
                    dir="ltr"
                    type="url"
                    placeholder="https://"
                    class="h-8 flex-1 text-sm md:max-w-96 pointer-coarse:h-11"
                    @keydown.enter.prevent="applyLink"
                />
                <ToolbarButton :icon="Check" title="تطبيق الرابط" @click="applyLink" />
            </div>

            <p v-if="uploadError" class="border-b p-2 text-xs text-destructive">{{ uploadError }}</p>

            <EditorContent :editor="editor" class="editor-content-well overflow-x-auto" />

            <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFileInputChange" />
        </div>
    </div>
</template>

<style scoped>
/* Wide content (tables, long lists) must scroll inside the well, never widen the page. */
.editor-content-well :deep(.ProseMirror) {
    max-width: 100%;
}

.editor-content-well :deep(.ProseMirror .tableWrapper) {
    max-width: 100%;
    overflow-x: auto;
}
</style>
