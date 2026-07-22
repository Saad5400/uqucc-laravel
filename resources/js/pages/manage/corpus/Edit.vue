<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import CorpusStatusBadge from '@/components/manage/corpus/CorpusStatusBadge.vue';
import {
    authoringStatusLabels,
    canAuthor,
    fileKindLabels,
    proposalStatusLabels,
    retrievalToggleDisabledReason,
    type AuthoringGate,
    type CorpusDocumentWorkspace,
} from '@/components/manage/corpus/types';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatFileSize } from '@/lib/formatters';
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import { ChevronLeft, FilePlus2, FileSearch, Loader2, RefreshCw, Sparkles, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    document: CorpusDocumentWorkspace;
    authoring: AuthoringGate;
}>();

/** Extraction and authoring run in the background — poll so الحالة moves on its own. */
usePoll(15_000, { only: ['document'] });

const form = useForm({
    title: props.document.title,
    extracted_markdown: props.document.extracted_markdown ?? '',
    reference_url: props.document.reference_url ?? '',
});

const canReingestNow = computed(() => props.document.status === 'ready' && (props.document.extracted_markdown ?? '').trim() !== '');

function submit(): void {
    form.transform((data) => ({
        title: data.title,
        extracted_markdown: data.extracted_markdown.trim() === '' ? null : data.extracted_markdown,
        reference_url: data.reference_url.trim() === '' ? null : data.reference_url.trim(),
    })).put(`/manage/corpus/${props.document.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.defaults(),
    });
}

/* ------------------------------------------------------------------ */
/* Header actions (each behind a ConfirmDialog)                        */
/* ------------------------------------------------------------------ */

type ConfirmableAction = 'reextract' | 'reingest' | 'delete' | 'author';

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

/* ------------------------------------------------------------------ */
/* Retrieval switch (cheap, reversible — applies immediately)          */
/* ------------------------------------------------------------------ */

const togglingRetrieval = ref(false);

const retrievalDisabledReason = computed(() => retrievalToggleDisabledReason(props.document));

function toggleRetrieval(): void {
    if (!props.document.is_indexed || togglingRetrieval.value) {
        return;
    }

    togglingRetrieval.value = true;

    router.post(
        `/manage/corpus/${props.document.id}/toggle`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['document'],
            onFinish: () => {
                togglingRetrieval.value = false;
            },
        },
    );
}

/* ------------------------------------------------------------------ */
/* Document → page authoring                                           */
/* ------------------------------------------------------------------ */

const authoringInFlight = computed(() => props.document.authoring_status === 'queued' || props.document.authoring_status === 'running');

/** Why the authoring action is unavailable — null when it can run. */
const authoringDisabledReason = computed<string | null>(() => {
    if (!props.authoring.enabled) {
        return props.authoring.disabled_reason;
    }

    if (authoringInFlight.value) {
        return 'يوجد توليد قيد التنفيذ لهذا المستند بالفعل.';
    }

    if (!canAuthor({ ...props.document, has_markdown: (props.document.extracted_markdown ?? '').trim() !== '' })) {
        return 'لا يمكن توليد صفحة قبل اكتمال استخراج نص المستند.';
    }

    return null;
});
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
                <Badge variant="secondary">{{ fileKindLabels[document.kind] }}</Badge>
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

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-4">
            <div class="min-w-0 space-y-1">
                <div class="flex items-center gap-2">
                    <span class="font-medium">التفعيل في البحث الذكي</span>
                    <Badge :variant="document.retrieval_enabled ? 'secondary' : 'outline'">
                        {{ document.retrieval_enabled ? 'مفعّل' : 'معطّل' }}
                    </Badge>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{
                        retrievalDisabledReason ??
                        'عند التعطيل يبقى المستند وملفه ونصه المفهرس كما هي، لكن لا يستخدمه المساعد الذكي في إجاباته حتى تعيد تفعيله.'
                    }}
                </p>
            </div>
            <div class="shrink-0" :title="retrievalDisabledReason ?? undefined">
                <Switch
                    :model-value="document.retrieval_enabled"
                    :disabled="!document.is_indexed || togglingRetrieval"
                    aria-label="تفعيل المستند في البحث الذكي"
                    @update:model-value="toggleRetrieval"
                />
            </div>
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
                        <Label for="corpus-reference-url">رابط المصدر (اختياري)</Label>
                        <Input
                            id="corpus-reference-url"
                            v-model="form.reference_url"
                            type="url"
                            dir="ltr"
                            inputmode="url"
                            placeholder="https://…"
                            maxlength="2048"
                            :aria-invalid="form.errors.reference_url ? true : undefined"
                        />
                        <p class="text-xs text-muted-foreground">
                            الرابط الذي يستشهد به المساعد الذكي كمصدر لهذا المستند. اتركه فارغاً ليستخدم رابط الملف الافتراضي.
                        </p>
                        <p v-if="form.errors.reference_url" class="text-sm text-destructive-foreground">
                            {{ form.errors.reference_url }}
                        </p>
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

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-lg">
                    <Sparkles class="size-4 text-muted-foreground" aria-hidden="true" />
                    توليد صفحة من المستند
                </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    يقرأ الذكاء الاصطناعي نص المستند ثم ينشئ مسودة صفحة جديدة غير منشورة، أو يقترح تحديثاً لصفحة موجودة تراجعه قبل تطبيقه — لن يُنشر
                    أي شيء تلقائياً.
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <Button size="sm" :disabled="authoringDisabledReason !== null" @click="confirmingAction = 'author'">
                        <Loader2 v-if="authoringInFlight" class="size-4 animate-spin" />
                        <Sparkles v-else class="size-4" />
                        {{ authoringInFlight ? 'جارٍ التوليد…' : 'توليد صفحة من المستند' }}
                    </Button>
                    <Badge v-if="document.authoring_status" :variant="document.authoring_status === 'failed' ? 'destructive' : 'secondary'">
                        {{ authoringStatusLabels[document.authoring_status] }}
                    </Badge>
                </div>

                <p v-if="authoringDisabledReason && !authoringInFlight" class="text-xs text-muted-foreground">{{ authoringDisabledReason }}</p>

                <div v-if="document.authoring_error" class="rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3">
                    <p class="text-sm text-destructive-foreground">{{ document.authoring_error }}</p>
                </div>

                <div v-if="document.authored_page || document.latest_proposal" class="flex flex-wrap items-center gap-2">
                    <Link
                        v-if="document.authored_page"
                        :href="`/manage/pages/${document.authored_page.id}/edit`"
                        class="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted px-3 py-1 text-sm text-foreground transition-colors hover:bg-accent"
                    >
                        <FilePlus2 class="size-3.5" />
                        تم إنشاء مسودة صفحة: «{{ document.authored_page.title }}»
                    </Link>
                    <Link
                        v-if="document.latest_proposal"
                        :href="`/manage/corpus/proposals/${document.latest_proposal.id}`"
                        class="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted px-3 py-1 text-sm text-foreground transition-colors hover:bg-accent"
                    >
                        <Sparkles class="size-3.5" />
                        اقتراح تحديث صفحة «{{ document.latest_proposal.page_title ?? '—' }}» ({{
                            proposalStatusLabels[document.latest_proposal.status]
                        }})
                    </Link>
                </div>
            </CardContent>
        </Card>

        <ConfirmDialog
            :open="confirmingAction === 'author'"
            title="توليد صفحة من المستند"
            confirm-label="توليد"
            :processing="processing"
            @confirm="runConfirmedAction"
            @update:open="(value) => (confirmingAction = value ? 'author' : null)"
        >
            سيقرأ الذكاء الاصطناعي نص المستند ثم ينشئ مسودة صفحة جديدة غير منشورة، أو يقترح تحديثاً لصفحة موجودة بانتظار مراجعتك — لن يُنشر أي شيء
            تلقائياً.
        </ConfirmDialog>

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
