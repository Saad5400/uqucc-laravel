<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatFileSize } from '@/lib/formatters';
import { useForm } from '@inertiajs/vue3';
import { FileUp, Loader2, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const open = defineModel<boolean>('open', { default: false });

const form = useForm<{ title: string; file: File | null }>({
    title: '',
    file: null,
});

const fileInput = ref<HTMLInputElement | null>(null);
const dragging = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.reset();
        dragging.value = false;

        if (fileInput.value) {
            fileInput.value.value = '';
        }
    }
});

function handleFileChange(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function handleDrop(event: DragEvent): void {
    dragging.value = false;
    const file = event.dataTransfer?.files?.[0] ?? null;

    if (file) {
        form.file = file;
        form.clearErrors('file');
    }
}

function removeFile(): void {
    form.file = null;

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function submit(): void {
    if (!form.file) {
        form.setError('file', 'يرجى اختيار ملف.');

        return;
    }

    form.post('/manage/corpus', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent :show-close-button="!form.processing">
            <DialogHeader>
                <DialogTitle>رفع مستند</DialogTitle>
                <DialogDescription>يُستخرج نص المستند تلقائياً بعد الرفع ثم يُفهرس في البحث الذكي.</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="corpus-upload-title">العنوان</Label>
                    <Input
                        id="corpus-upload-title"
                        v-model="form.title"
                        type="text"
                        required
                        maxlength="255"
                        :aria-invalid="form.errors.title ? true : undefined"
                    />
                    <p class="text-xs text-muted-foreground">اسم واضح للمستند كما سيظهر في نتائج البحث الذكي (مثال: لائحة الدراسة والاختبارات).</p>
                    <p v-if="form.errors.title" class="text-sm text-destructive-foreground">{{ form.errors.title }}</p>
                </div>
                <div class="space-y-2">
                    <Label for="corpus-upload-file">الملف</Label>
                    <input
                        id="corpus-upload-file"
                        ref="fileInput"
                        type="file"
                        class="sr-only"
                        accept="application/pdf,image/png,image/jpeg,image/webp"
                        :aria-invalid="form.errors.file ? true : undefined"
                        @change="handleFileChange"
                    />
                    <label
                        v-if="!form.file"
                        for="corpus-upload-file"
                        class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-6 text-center transition-colors"
                        :class="dragging ? 'border-primary bg-primary/5' : 'border-input hover:border-muted-foreground/50 hover:bg-accent/50'"
                        @dragover.prevent="dragging = true"
                        @dragleave="dragging = false"
                        @drop.prevent="handleDrop"
                    >
                        <FileUp class="size-6 text-muted-foreground" aria-hidden="true" />
                        <span class="text-sm font-medium">اختر ملفاً أو أفلته هنا</span>
                        <span class="text-xs text-muted-foreground"><span dir="ltr">PDF / PNG / JPG / WebP</span> — بحد أقصى 20 ميجابايت</span>
                    </label>
                    <div v-else class="flex items-center gap-2 rounded-lg border border-input px-3 py-2.5">
                        <FileUp class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <span dir="ltr" class="min-w-0 flex-1 truncate text-start text-sm">{{ form.file.name }}</span>
                        <span dir="ltr" class="shrink-0 text-xs text-muted-foreground tabular-nums">{{ formatFileSize(form.file.size) }}</span>
                        <Button type="button" variant="ghost" size="icon-sm" class="-me-1 shrink-0" aria-label="إزالة الملف" @click="removeFile">
                            <X />
                        </Button>
                    </div>
                    <p v-if="form.errors.file" class="text-sm text-destructive-foreground">{{ form.errors.file }}</p>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="open = false">إلغاء</Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        رفع المستند
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
