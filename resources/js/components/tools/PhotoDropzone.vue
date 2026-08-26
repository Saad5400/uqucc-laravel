<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { formatFileSize } from '@/lib/formatters';
import { MAX_INPUT_BYTES } from '@/lib/studentPhoto/decode';
import { ImageUp } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';

interface Props {
    /** Disables picking while a photo is being decoded. */
    busy?: boolean;
    /** Listen for a pasted image anywhere on the page (⌘/Ctrl+V). */
    listenForPaste?: boolean;
}

const props = withDefaults(defineProps<Props>(), { busy: false, listenForPaste: true });

const emit = defineEmits<{
    (event: 'select', file: File): void;
    (event: 'reject', message: string): void;
}>();

const fileInput = ref<HTMLInputElement | null>(null);
const isDragging = ref(false);
/** Drag events fire per child element, so nesting is counted rather than toggled. */
const dragDepth = ref(0);

function openPicker(): void {
    if (!props.busy) {
        fileInput.value?.click();
    }
}

function takeFirstFile(files: FileList | null | undefined, droppedItems?: DataTransferItemList | null): void {
    const list = files ? [...files] : [];

    if (list.length === 0) {
        const droppedFolder = droppedItems && [...droppedItems].some((item) => item.webkitGetAsEntry?.()?.isDirectory);

        emit('reject', droppedFolder ? 'أفلِت ملف صورة واحدًا لا مجلدًا.' : 'لم يصل أي ملف. جرّب اختيار الصورة من الزر.');

        return;
    }

    if (list.length > 1) {
        emit('reject', 'اخترت أكثر من ملف — سنستخدم الأول فقط.');
    }

    emit('select', list[0]);
}

function handleInputChange(event: Event): void {
    const input = event.target as HTMLInputElement;

    takeFirstFile(input.files);

    // Reset so re-picking the same file still fires a change event.
    input.value = '';
}

function handleDrop(event: DragEvent): void {
    dragDepth.value = 0;
    isDragging.value = false;

    if (props.busy) {
        return;
    }

    takeFirstFile(event.dataTransfer?.files, event.dataTransfer?.items);
}

function handleDragEnter(): void {
    dragDepth.value += 1;
    isDragging.value = true;
}

function handleDragLeave(): void {
    dragDepth.value = Math.max(0, dragDepth.value - 1);

    if (dragDepth.value === 0) {
        isDragging.value = false;
    }
}

function handlePaste(event: ClipboardEvent): void {
    if (props.busy || !props.listenForPaste) {
        return;
    }

    const pasted = [...(event.clipboardData?.items ?? [])].find((item) => item.kind === 'file');
    const file = pasted?.getAsFile();

    if (file) {
        event.preventDefault();
        emit('select', file);
    }
}

onMounted(() => window.addEventListener('paste', handlePaste));
onBeforeUnmount(() => window.removeEventListener('paste', handlePaste));
</script>

<template>
    <div
        class="flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed p-8 text-center transition-colors"
        :class="isDragging ? 'border-primary bg-primary/5' : 'border-border bg-muted/40'"
        @dragenter.prevent="handleDragEnter"
        @dragover.prevent
        @dragleave.prevent="handleDragLeave"
        @drop.prevent="handleDrop"
    >
        <ImageUp class="size-10 text-muted-foreground" aria-hidden="true" />

        <div class="space-y-1">
            <p class="!my-0 font-medium">اسحب صورتك هنا، أو الصقها بلوحة المفاتيح</p>
            <p class="!my-0 text-sm text-muted-foreground">
                JPG أو PNG أو WEBP — حتى {{ formatFileSize(MAX_INPUT_BYTES) }}. الصورة لا تُرفع لأي خادم، وكل المعالجة داخل متصفحك.
            </p>
        </div>

        <Button type="button" :disabled="busy" @click="openPicker">
            {{ busy ? 'جارٍ فتح الصورة…' : 'اختر صورة' }}
        </Button>

        <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="handleInputChange" />
    </div>
</template>
