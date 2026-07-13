<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import DiffView from '@/components/manage/reviews/DiffView.vue';
import { changeStatusLabels, type ReviewChangePayload } from '@/components/manage/reviews/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/formatters';
import { renderMarkdown } from '@/lib/markdown';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, ChevronLeft, ExternalLink, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    change: ReviewChangePayload;
}>();

const isPending = computed(() => props.change.status === 'pending');

/** Content fields can render as a colored line diff or as rendered previews. */
type ContentView = 'diff' | 'preview';

const contentView = ref<ContentView>('diff');
const contentViews: { value: ContentView; label: string }[] = [
    { value: 'diff', label: 'الفروقات' },
    { value: 'preview', label: 'معاينة' },
];

const hasMarkdownChange = computed(() => props.change.changes.some((field) => field.type === 'markdown'));

/** Why approve/reject are unavailable — null when the reviewer can act. */
const actionDisabledReason = computed<string | null>(() => {
    if (!isPending.value) {
        return 'هذا التعديل لم يعد بانتظار المراجعة.';
    }

    if (props.change.page === null) {
        return 'الصفحة المستهدفة لم تعد موجودة — لا يمكن اعتماد التعديل.';
    }

    return null;
});

const statusVariant = computed(() => {
    if (props.change.status === 'rejected') {
        return 'destructive' as const;
    }

    return props.change.status === 'pending' ? ('default' as const) : ('secondary' as const);
});

function boolLabel(value: string | boolean): string {
    return value ? 'نعم' : 'لا';
}

type ConfirmableAction = 'approve' | 'reject';

const confirmingAction = ref<ConfirmableAction | null>(null);
const processing = ref(false);

function runConfirmedAction(): void {
    if (!confirmingAction.value) {
        return;
    }

    processing.value = true;

    router.post(
        `/manage/reviews/${props.change.id}/${confirmingAction.value}`,
        {},
        {
            onSuccess: () => {
                confirmingAction.value = null;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`مراجعة تعديل — ${change.page?.title ?? 'صفحة محذوفة'}`" />

    <div class="space-y-4">
        <nav aria-label="مسار المراجعة" class="flex flex-wrap items-center gap-1 text-sm text-muted-foreground">
            <Link href="/manage/reviews" class="transition-colors hover:text-foreground">المراجعات</Link>
            <ChevronLeft class="size-3.5" aria-hidden="true" />
            <span class="text-foreground">مراجعة تعديل</span>
        </nav>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0 flex-1 space-y-1">
                <h1 class="truncate text-2xl font-bold">تعديل صفحة «{{ change.page?.title ?? '—' }}»</h1>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <span v-if="change.author_name">اقترحه {{ change.author_name }}</span>
                    <span v-if="change.created_at">{{ formatDateTime(change.created_at) }}</span>
                    <span v-if="change.reviewer_name">· راجعه {{ change.reviewer_name }}</span>
                </div>
            </div>

            <Badge :variant="statusVariant">{{ changeStatusLabels[change.status] }}</Badge>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button :disabled="actionDisabledReason !== null" :title="actionDisabledReason ?? undefined" @click="confirmingAction = 'approve'">
                <Check class="size-4" />
                اعتماد ونشر
            </Button>
            <Button
                variant="outline"
                :disabled="actionDisabledReason !== null"
                :title="actionDisabledReason ?? undefined"
                @click="confirmingAction = 'reject'"
            >
                <X class="size-4" />
                رفض
            </Button>
            <Link
                v-if="change.page && !change.page.trashed"
                :href="`/manage/pages/${change.page.id}/edit`"
                class="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                <ExternalLink class="size-3.5" />
                فتح الصفحة في المحرر
            </Link>
        </div>

        <p v-if="actionDisabledReason && !isPending" class="text-xs text-muted-foreground">{{ actionDisabledReason }}</p>

        <p v-if="!change.changes.length" class="rounded-lg border border-border bg-muted/50 px-4 py-3 text-sm text-muted-foreground">
            لا تتضمن هذه المراجعة أي تغييرات.
        </p>

        <div v-if="hasMarkdownChange" role="tablist" aria-label="طريقة عرض المحتوى" class="flex w-fit gap-1 rounded-lg bg-muted p-1">
            <button
                v-for="view in contentViews"
                :key="view.value"
                type="button"
                role="tab"
                :aria-selected="contentView === view.value"
                class="rounded-md px-4 py-1.5 text-sm font-medium whitespace-nowrap transition-colors"
                :class="contentView === view.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                @click="contentView = view.value"
            >
                {{ view.label }}
            </button>
        </div>

        <div v-for="field in change.changes" :key="field.key" class="space-y-2 rounded-lg border border-border p-4">
            <h2 class="text-sm font-semibold">{{ field.label }}</h2>

            <template v-if="field.type === 'markdown'">
                <DiffView v-if="contentView === 'diff'" :old="String(field.old)" :new="String(field.new)" />
                <div v-else class="grid gap-4 lg:grid-cols-2">
                    <div class="space-y-1">
                        <p class="text-xs text-muted-foreground">الحالي</p>
                        <p v-if="String(field.old).trim() === ''" class="text-sm text-muted-foreground">فارغ.</p>
                        <!-- eslint-disable-next-line vue/no-v-html -- renderMarkdown escapes + DOMPurify-sanitizes the content -->
                        <div v-else class="review-markdown text-sm leading-relaxed" v-html="renderMarkdown(String(field.old))" />
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs text-muted-foreground">المقترح</p>
                        <p v-if="String(field.new).trim() === ''" class="text-sm text-muted-foreground">فارغ.</p>
                        <!-- eslint-disable-next-line vue/no-v-html -- renderMarkdown escapes + DOMPurify-sanitizes the content -->
                        <div v-else class="review-markdown text-sm leading-relaxed" v-html="renderMarkdown(String(field.new))" />
                    </div>
                </div>
            </template>

            <div v-else class="flex flex-wrap items-center gap-2 text-sm">
                <span class="rounded-md bg-muted px-2 py-1 text-muted-foreground">
                    {{ field.type === 'bool' ? boolLabel(field.old) : field.old }}
                </span>
                <ChevronLeft class="size-4 text-muted-foreground" aria-hidden="true" />
                <span class="rounded-md bg-primary/10 px-2 py-1 font-medium text-foreground">
                    {{ field.type === 'bool' ? boolLabel(field.new) : field.new }}
                </span>
            </div>
        </div>

        <ConfirmDialog
            :open="confirmingAction === 'approve'"
            title="اعتماد التعديل"
            confirm-label="اعتماد ونشر"
            :processing="processing"
            @confirm="runConfirmedAction"
            @update:open="(value) => (confirmingAction = value ? 'approve' : null)"
        >
            سيُطبَّق هذا التعديل على صفحة «{{ change.page?.title ?? '—' }}» ويظهر على الموقع مباشرةً.
        </ConfirmDialog>

        <ConfirmDialog
            :open="confirmingAction === 'reject'"
            title="رفض التعديل"
            destructive
            confirm-label="رفض"
            :processing="processing"
            @confirm="runConfirmedAction"
            @update:open="(value) => (confirmingAction = value ? 'reject' : null)"
        >
            سيُرفض التعديل ولن تتغيّر الصفحة. لا يمكن التراجع عن الرفض.
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.review-markdown :deep(p) {
    margin-block: 0.375rem;
}

.review-markdown :deep(p:first-child) {
    margin-top: 0;
}

.review-markdown :deep(p:last-child) {
    margin-bottom: 0;
}

.review-markdown :deep(ul),
.review-markdown :deep(ol) {
    margin-block: 0.375rem;
    padding-inline-start: 1.25rem;
}

.review-markdown :deep(ul) {
    list-style: disc;
}

.review-markdown :deep(ol) {
    list-style: decimal;
}

.review-markdown :deep(h2),
.review-markdown :deep(h3),
.review-markdown :deep(h4) {
    margin-block: 0.625rem 0.25rem;
    font-weight: 600;
}

.review-markdown :deep(a) {
    color: var(--primary);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.review-markdown :deep(table) {
    display: block;
    width: max-content;
    max-width: 100%;
    overflow-x: auto;
    margin-block: 0.5rem;
    border-collapse: collapse;
    font-size: 0.8125rem;
    font-variant-numeric: tabular-nums;
}

.review-markdown :deep(th),
.review-markdown :deep(td) {
    border: 1px solid var(--border);
    padding: 0.375rem 0.625rem;
    text-align: start;
    vertical-align: top;
}
</style>
