<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import CorpusStatusBadge from '@/components/manage/corpus/CorpusStatusBadge.vue';
import { type CorpusDocumentWorkspace } from '@/components/manage/corpus/types';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatFileSize } from '@/lib/formatters';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ChevronLeft, FileSearch, Loader2, RefreshCw, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    document: CorpusDocumentWorkspace;
}>();

const form = useForm({
    title: props.document.title,
    extracted_markdown: props.document.extracted_markdown ?? '',
});

const canReingestNow = computed(() => props.document.status === 'ready' && (props.document.extracted_markdown ?? '').trim() !== '');

function submit(): void {
    form.transform((data) => ({
        title: data.title,
        extracted_markdown: data.extracted_markdown.trim() === '' ? null : data.extracted_markdown,
    })).put(`/manage/corpus/${props.document.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.defaults(),
    });
}

/* ------------------------------------------------------------------ */
/* Header actions (each behind a ConfirmDialog)                        */
/* ------------------------------------------------------------------ */

type ConfirmableAction = 'reextract' | 'reingest' | 'delete';

const confirmingAction = ref<ConfirmableAction | null>(null);
const processing = ref(false);

function runConfirmedAction(): void {
    if (!confirmingAction.value) {
        return;
    }

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            confirmingAction.value = null;
        },
        onFinish: () => {
            processing.value = false;
        },
    };

    processing.value = true;

    if (confirmingAction.value === 'delete') {
        router.delete(`/manage/corpus/${props.document.id}`, options);
    } else {
        router.post(`/manage/corpus/${props.document.id}/${confirmingAction.value}`, {}, options);
    }
}
</script>

<template>
    <Head :title="document.title" />

    <div class="space-y-4">
        <nav aria-label="مسار المستند" class="flex flex-wrap items-center gap-1 text-sm text-muted-foreground">
            <Link href="/manage/corpus" class="transition-colors hover:text-foreground">مستندات الذكاء الاصطناعي</Link>
            <ChevronLeft class="size-3.5" aria-hidden="true" />
            <span class="text-foreground">{{ document.title }}</span>
        </nav>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0 flex-1 space-y-1">
                <h1 class="truncate text-2xl font-bold">{{ document.title }}</h1>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <span dir="ltr" class="truncate">{{ document.original_filename }}</span>
                    <span v-if="document.size !== null" dir="ltr" class="tabular-nums">{{ formatFileSize(document.size) }}</span>
                    <span v-if="document.uploader_name">رفعه {{ document.uploader_name }}</span>
                    <span v-if="document.created_at">{{ formatDateTime(document.created_at) }}</span>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Badge variant="secondary">{{ document.is_pdf ? 'PDF' : 'صورة' }}</Badge>
                <CorpusStatusBadge kind="extraction" :status="document.status" :error="document.error" />
                <CorpusStatusBadge kind="index" :status="document.index_status" />
            </div>
        </div>

        <div v-if="document.error" class="rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3">
            <p class="text-sm text-destructive-foreground">{{ document.error }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button variant="outline" size="sm" @click="confirmingAction = 'reextract'">
                <RefreshCw class="size-4" />
                إعادة الاستخراج
            </Button>
            <Button v-if="canReingestNow" variant="outline" size="sm" @click="confirmingAction = 'reingest'">
                <FileSearch class="size-4" />
                إعادة الفهرسة
            </Button>
            <Button variant="outline" size="sm" class="text-destructive-foreground" @click="confirmingAction = 'delete'">
                <Trash2 class="size-4" />
                حذف
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-lg">بيانات المستند</CardTitle>
            </CardHeader>
            <CardContent>
                <form class="space-y-6" @submit.prevent="submit">
                    <div class="space-y-2">
                        <Label for="corpus-title">العنوان</Label>
                        <Input
                            id="corpus-title"
                            v-model="form.title"
                            type="text"
                            required
                            maxlength="255"
                            :aria-invalid="form.errors.title ? true : undefined"
                        />
                        <p class="text-xs text-muted-foreground">
                            اسم واضح للمستند كما سيظهر في نتائج البحث الذكي (مثال: لائحة الدراسة والاختبارات).
                        </p>
                        <p v-if="form.errors.title" class="text-sm text-destructive-foreground">{{ form.errors.title }}</p>
                    </div>

                    <div class="space-y-2">
                        <Label for="corpus-markdown">النص المستخرج (ماركداون)</Label>
                        <Textarea
                            id="corpus-markdown"
                            v-model="form.extracted_markdown"
                            dir="auto"
                            rows="20"
                            class="min-h-96 font-mono text-sm"
                            :aria-invalid="form.errors.extracted_markdown ? true : undefined"
                        />
                        <p class="text-xs text-muted-foreground">
                            النص الذي استخرجه النظام من الملف. يمكن تصحيحه يدوياً — سيُعاد فهرسة المستند تلقائياً بعد الحفظ.
                        </p>
                        <p v-if="form.errors.extracted_markdown" class="text-sm text-destructive-foreground">
                            {{ form.errors.extracted_markdown }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <p v-if="!form.isDirty && !form.processing" class="text-xs text-muted-foreground">لا توجد تغييرات لحفظها</p>
                        <Button type="submit" :disabled="!form.isDirty || form.processing">
                            <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                            حفظ المستند
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <ConfirmDialog
            :open="confirmingAction === 'reextract'"
            title="إعادة استخراج النص"
            confirm-label="إعادة الاستخراج"
            :processing="processing"
            @confirm="runConfirmedAction"
            @update:open="(value) => (confirmingAction = value ? 'reextract' : null)"
        >
            سيُعاد استخراج النص من الملف (عبر طبقة النص أو نموذج الرؤية) ثم تُعاد فهرسته. يستبدل هذا أي تعديلات يدوية على النص المستخرج.
        </ConfirmDialog>

        <ConfirmDialog
            :open="confirmingAction === 'reingest'"
            title="إعادة الفهرسة"
            confirm-label="إعادة الفهرسة"
            :processing="processing"
            @confirm="runConfirmedAction"
            @update:open="(value) => (confirmingAction = value ? 'reingest' : null)"
        >
            ستُعاد تجزئة النص المستخرج وتضمينه في فهرس البحث الذكي دون إعادة الاستخراج.
        </ConfirmDialog>

        <ConfirmDialog
            :open="confirmingAction === 'delete'"
            title="حذف المستند"
            destructive
            confirm-label="حذف"
            :processing="processing"
            @confirm="runConfirmedAction"
            @update:open="(value) => (confirmingAction = value ? 'delete' : null)"
        >
            سيُحذف المستند «{{ document.title }}» وملفه المخزن وكل مقاطعه من فهرس البحث الذكي.
        </ConfirmDialog>
    </div>
</template>
