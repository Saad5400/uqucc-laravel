<script setup lang="ts">
import type { AssistantProposal } from '@/components/manage/assistant/types';
import { Button } from '@/components/ui/button';
import { confirm as confirmProposal, reject as rejectProposal } from '@/routes/manage/assistant/proposals';
import { Check, CircleAlert, CircleCheck, CircleX, FileText, Loader2, Settings2, Users, X } from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed, ref } from 'vue';

/**
 * One inline action card for a change the assistant proposed: the human
 * summary, the change details, and the two-phase gate — تأكيد applies the
 * change on the server, رفض declines it. After acting, the card collapses
 * into a status chip. Nothing the assistant proposes happens without a
 * click here. Works for any unified admin action (grouped by category).
 */

const props = defineProps<{
    proposal: AssistantProposal;
}>();

const emit = defineEmits<{
    updated: [proposal: AssistantProposal];
}>();

const acting = ref<'confirm' | 'reject' | null>(null);
const actionError = ref<string | null>(null);

/** Icon + heading per action category. */
const categoryMeta: Record<string, { icon: Component; label: string }> = {
    pages: { icon: FileText, label: 'تغيير مقترح على الصفحات' },
    settings: { icon: Settings2, label: 'تغيير مقترح على الإعدادات' },
    tutors: { icon: Users, label: 'تغيير مقترح على المدرّسين' },
    users: { icon: Users, label: 'تغيير مقترح على المستخدمين' },
    reviews: { icon: FileText, label: 'إجراء مقترح على المراجعات' },
};

const meta = computed(() => categoryMeta[props.proposal.category] ?? { icon: FileText, label: 'تغيير مقترح' });

/** Arabic labels for the normalized detail keys worth showing on the card. */
const detailLabels: Record<string, string> = {
    action: 'الإجراء',
    page_title: 'الصفحة',
    title: 'العنوان الجديد',
    parent_title: 'الصفحة الأب',
    group: 'المجموعة',
    key: 'الإعداد',
    old_value: 'القيمة الحالية',
    value: 'القيمة الجديدة',
    name: 'الاسم',
};

const actionLabels: Record<string, string> = {
    create: 'إنشاء صفحة',
    rename: 'إعادة تسمية',
    move: 'نقل',
    reorder: 'إعادة ترتيب',
    publish: 'نشر',
    unpublish: 'إخفاء',
    delete: 'حذف',
};

const scalar = (value: unknown): string | null => {
    if (value === undefined || value === null || value === '') {
        return null;
    }

    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    return null;
};

const details = computed(() => {
    const entries: { label: string; value: string }[] = [];
    const source = props.proposal.details ?? {};

    for (const key of Object.keys(detailLabels)) {
        const raw = scalar(source[key]);

        if (raw === null) {
            continue;
        }

        const value = key === 'action' ? (actionLabels[raw] ?? raw) : raw;

        entries.push({ label: detailLabels[key], value });
    }

    const titles = source.titles;

    if (Array.isArray(titles) && titles.length > 0) {
        entries.push({ label: 'الترتيب الجديد', value: titles.map((title, index) => `${index + 1}. ${String(title)}`).join(' — ') });
    }

    return entries;
});

const xsrfToken = (): string => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

const act = async (kind: 'confirm' | 'reject'): Promise<void> => {
    if (acting.value !== null || props.proposal.status !== 'pending') {
        return;
    }

    acting.value = kind;
    actionError.value = null;

    const route = kind === 'confirm' ? confirmProposal : rejectProposal;

    try {
        const response = await fetch(route.url(props.proposal.id), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-XSRF-TOKEN': xsrfToken(),
                Accept: 'application/json',
            },
        });

        const payload = (await response.json().catch(() => null)) as { message?: string; proposal?: AssistantProposal } | null;

        if (payload?.proposal) {
            emit('updated', payload.proposal);
        } else if (!response.ok) {
            actionError.value = payload?.message ?? 'تعذر تنفيذ الطلب. حاول مرة أخرى.';
        }
    } catch {
        actionError.value = 'تعذر الاتصال بالخادم. تأكد من اتصالك ثم أعد المحاولة.';
    } finally {
        acting.value = null;
    }
};
</script>

<template>
    <div class="rounded-lg border border-border bg-background p-3 shadow-sm">
        <div class="flex items-start gap-2">
            <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <component :is="meta.icon" class="size-3.5" />
            </span>
            <div class="min-w-0 flex-1 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-muted-foreground">{{ meta.label }}</span>
                    <span
                        v-if="proposal.status === 'confirmed'"
                        class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                    >
                        <CircleCheck class="size-3" />
                        تم التنفيذ
                    </span>
                    <span
                        v-else-if="proposal.status === 'rejected'"
                        class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                    >
                        <CircleX class="size-3" />
                        مرفوض
                    </span>
                    <span
                        v-else-if="proposal.status === 'failed'"
                        class="inline-flex items-center gap-1 rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive"
                    >
                        <CircleAlert class="size-3" />
                        فشل التنفيذ
                    </span>
                </div>

                <p class="text-sm font-medium">{{ proposal.summary }}</p>

                <dl v-if="details.length > 0" class="space-y-0.5 text-xs text-muted-foreground">
                    <div v-for="detail in details" :key="detail.label" class="flex gap-1.5">
                        <dt class="shrink-0 font-medium">{{ detail.label }}:</dt>
                        <dd class="min-w-0 break-words">{{ detail.value }}</dd>
                    </div>
                </dl>

                <p v-if="proposal.status === 'failed' && proposal.error" class="text-xs text-destructive">{{ proposal.error }}</p>
                <p v-if="actionError" class="text-xs text-destructive">{{ actionError }}</p>

                <div v-if="proposal.status === 'pending'" class="flex items-center gap-2 pt-1">
                    <Button size="sm" class="gap-1.5" :disabled="acting !== null" @click="act('confirm')">
                        <Loader2 v-if="acting === 'confirm'" class="size-3.5 animate-spin" />
                        <Check v-else class="size-3.5" />
                        تأكيد
                    </Button>
                    <Button size="sm" variant="outline" class="gap-1.5" :disabled="acting !== null" @click="act('reject')">
                        <Loader2 v-if="acting === 'reject'" class="size-3.5 animate-spin" />
                        <X v-else class="size-3.5" />
                        رفض
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
