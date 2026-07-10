<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import { proposalStatusLabels, type AuthoringGate, type ProposalStatus } from '@/components/manage/corpus/types';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/formatters';
import { renderMarkdown } from '@/lib/markdown';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, ChevronLeft, ExternalLink, Sparkles, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

/** The proposal as manage.corpus.proposals.show serializes it. */
interface ProposalReviewPayload {
    id: number;
    status: ProposalStatus;
    summary: string;
    error: string | null;
    proposed_markdown: string;
    created_at: string | null;
    applied_at: string | null;
    page: {
        id: number;
        title: string;
        slug: string;
        trashed: boolean;
        current_markdown: string;
    } | null;
    document: { id: number; title: string };
}

const props = defineProps<{
    proposal: ProposalReviewPayload;
    authoring: AuthoringGate;
}>();

const isPending = computed(() => props.proposal.status === 'pending');

/** Why apply/reject are unavailable — null when the reviewer can act. */
const actionDisabledReason = computed<string | null>(() => {
    if (!isPending.value) {
        return 'هذا الاقتراح لم يعد بانتظار المراجعة.';
    }

    if (!props.authoring.enabled) {
        return props.authoring.disabled_reason;
    }

    if (props.proposal.page === null || props.proposal.page.trashed) {
        return 'الصفحة المستهدفة لم تعد موجودة — لا يمكن تطبيق الاقتراح.';
    }

    return null;
});

const statusVariant = computed(() => {
    if (props.proposal.status === 'failed') {
        return 'destructive' as const;
    }

    return props.proposal.status === 'pending' ? ('default' as const) : ('secondary' as const);
});

type ConfirmableAction = 'apply' | 'reject';

const confirmingAction = ref<ConfirmableAction | null>(null);
const processing = ref(false);

function runConfirmedAction(): void {
    if (!confirmingAction.value) {
        return;
    }

    processing.value = true;

    router.post(
        `/manage/corpus/proposals/${props.proposal.id}/${confirmingAction.value}`,
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
    <Head :title="`مراجعة اقتراح تحديث — ${proposal.page?.title ?? 'صفحة محذوفة'}`" />

    <div class="space-y-4">
        <nav aria-label="مسار الاقتراح" class="flex flex-wrap items-center gap-1 text-sm text-muted-foreground">
            <Link href="/manage/corpus" class="transition-colors hover:text-foreground">مستندات الذكاء الاصطناعي</Link>
            <ChevronLeft class="size-3.5" aria-hidden="true" />
            <Link :href="`/manage/corpus/${proposal.document.id}/edit`" class="transition-colors hover:text-foreground">
                {{ proposal.document.title }}
            </Link>
            <ChevronLeft class="size-3.5" aria-hidden="true" />
            <span class="text-foreground">مراجعة اقتراح التحديث</span>
        </nav>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0 flex-1 space-y-1">
                <h1 class="flex items-center gap-2 text-2xl font-bold">
                    <Sparkles class="size-5 text-muted-foreground" aria-hidden="true" />
                    اقتراح تحديث صفحة «{{ proposal.page?.title ?? '—' }}»
                </h1>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <span>من مستند «{{ proposal.document.title }}»</span>
                    <span v-if="proposal.created_at">{{ formatDateTime(proposal.created_at) }}</span>
                </div>
            </div>

            <Badge :variant="statusVariant">{{ proposalStatusLabels[proposal.status] }}</Badge>
        </div>

        <div class="rounded-lg border border-border bg-muted/50 px-4 py-3">
            <p class="text-sm">{{ proposal.summary }}</p>
        </div>

        <div v-if="proposal.error" class="rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3">
            <p class="text-sm text-destructive-foreground">{{ proposal.error }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button :disabled="actionDisabledReason !== null" :title="actionDisabledReason ?? undefined" @click="confirmingAction = 'apply'">
                <Check class="size-4" />
                تطبيق الاقتراح
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
                v-if="proposal.page && !proposal.page.trashed"
                :href="`/manage/pages/${proposal.page.id}/edit`"
                class="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                <ExternalLink class="size-3.5" />
                فتح الصفحة في المحرر
            </Link>
        </div>

        <p v-if="actionDisabledReason && isPending" class="text-xs text-muted-foreground">{{ actionDisabledReason }}</p>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle class="text-lg">المحتوى الحالي</CardTitle>
                </CardHeader>
                <CardContent>
                    <p v-if="!proposal.page || proposal.page.current_markdown.trim() === ''" class="text-sm text-muted-foreground">
                        {{ proposal.page ? 'الصفحة فارغة حالياً.' : 'الصفحة المستهدفة لم تعد موجودة.' }}
                    </p>
                    <!-- eslint-disable-next-line vue/no-v-html -- renderMarkdown escapes + DOMPurify-sanitizes the content -->
                    <div v-else class="proposal-markdown text-sm leading-relaxed" v-html="renderMarkdown(proposal.page.current_markdown)" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-lg">المحتوى المقترح</CardTitle>
                </CardHeader>
                <CardContent>
                    <!-- eslint-disable-next-line vue/no-v-html -- renderMarkdown escapes + DOMPurify-sanitizes model output -->
                    <div class="proposal-markdown text-sm leading-relaxed" v-html="renderMarkdown(proposal.proposed_markdown)" />
                </CardContent>
            </Card>
        </div>

        <ConfirmDialog
            :open="confirmingAction === 'apply'"
            title="تطبيق الاقتراح"
            confirm-label="تطبيق"
            :processing="processing"
            @confirm="runConfirmedAction"
            @update:open="(value) => (confirmingAction = value ? 'apply' : null)"
        >
            سيستبدل المحتوى المقترح محتوى صفحة «{{ proposal.page?.title ?? '—' }}» ثم يفتح المحرر لمراجعتك النهائية قبل النشر. الصفحة تبقى بحالتها
            الحالية من حيث الظهور.
        </ConfirmDialog>

        <ConfirmDialog
            :open="confirmingAction === 'reject'"
            title="رفض الاقتراح"
            destructive
            confirm-label="رفض"
            :processing="processing"
            @confirm="runConfirmedAction"
            @update:open="(value) => (confirmingAction = value ? 'reject' : null)"
        >
            سيُرفض الاقتراح ولن تتغير الصفحة. لا يمكن التراجع عن الرفض، لكن يمكنك توليد اقتراح جديد من المستند لاحقاً.
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.proposal-markdown :deep(p) {
    margin-block: 0.375rem;
}

.proposal-markdown :deep(p:first-child) {
    margin-top: 0;
}

.proposal-markdown :deep(p:last-child) {
    margin-bottom: 0;
}

.proposal-markdown :deep(ul),
.proposal-markdown :deep(ol) {
    margin-block: 0.375rem;
    padding-inline-start: 1.25rem;
}

.proposal-markdown :deep(ul) {
    list-style: disc;
}

.proposal-markdown :deep(ol) {
    list-style: decimal;
}

.proposal-markdown :deep(h2),
.proposal-markdown :deep(h3),
.proposal-markdown :deep(h4) {
    margin-block: 0.625rem 0.25rem;
    font-weight: 600;
}

.proposal-markdown :deep(code) {
    border-radius: 0.25rem;
    background: var(--muted);
    padding: 0.125rem 0.375rem;
    font-size: 0.8125em;
    direction: ltr;
    unicode-bidi: embed;
}

.proposal-markdown :deep(pre) {
    margin-block: 0.5rem;
    overflow-x: auto;
    border-radius: 0.5rem;
    background: var(--muted);
    padding: 0.75rem;
    direction: ltr;
    text-align: left;
}

.proposal-markdown :deep(pre code) {
    padding: 0;
    background: transparent;
}

.proposal-markdown :deep(a) {
    color: var(--primary);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.proposal-markdown :deep(blockquote) {
    margin-block: 0.5rem;
    border-inline-start: 3px solid var(--border);
    padding-inline-start: 0.75rem;
    color: var(--muted-foreground);
}

.proposal-markdown :deep(table) {
    display: block;
    width: max-content;
    max-width: 100%;
    overflow-x: auto;
    margin-block: 0.5rem;
    border-collapse: collapse;
    font-size: 0.8125rem;
    font-variant-numeric: tabular-nums;
}

.proposal-markdown :deep(th),
.proposal-markdown :deep(td) {
    border: 1px solid var(--border);
    padding: 0.375rem 0.625rem;
    text-align: start;
    vertical-align: top;
}

.proposal-markdown :deep(th) {
    background: color-mix(in oklab, var(--foreground) 5%, transparent);
    font-weight: 600;
}
</style>
