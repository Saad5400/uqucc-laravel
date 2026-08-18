<script setup lang="ts">
import type { AssistantDecision, TrackedCard } from '@/components/manage/assistant/types';
import { Button } from '@/components/ui/button';
import {
    BookOpen,
    Check,
    CircleCheck,
    CircleX,
    FileText,
    HelpCircle,
    MessageCircleQuestion,
    Send,
    Settings2,
    TriangleAlert,
    Users,
    X,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed, reactive, ref } from 'vue';

/**
 * One inline card of a PAUSED assistant turn. An approval card shows the
 * server-derived summary and arguments with the two-phase gate — تأكيد
 * approves the call (editable cards let the admin adjust the fields first,
 * and the edited values travel the same validated tool path), رفض declines
 * it. A question card takes a free-text answer instead. Decisions are
 * batched by the parent: the turn resumes once every pending card is
 * decided, and nothing executes before that.
 */

const props = defineProps<{
    tracked: TrackedCard;
}>();

const emit = defineEmits<{
    decide: [decision: AssistantDecision];
}>();

/** Icon + heading per action category. */
const categoryMeta: Record<string, { icon: Component; label: string }> = {
    pages: { icon: FileText, label: 'تغيير على الصفحات' },
    settings: { icon: Settings2, label: 'تغيير على الإعدادات' },
    tutors: { icon: Users, label: 'تغيير على المدرّسين' },
    users: { icon: Users, label: 'تغيير على المستخدمين' },
    reviews: { icon: FileText, label: 'إجراء على المراجعات' },
    telegram: { icon: Send, label: 'تغيير على إعدادات تيليجرام' },
    corpus: { icon: BookOpen, label: 'إجراء على قاعدة المعرفة' },
    quiz: { icon: HelpCircle, label: 'إجراء على سؤال اليوم' },
    system: { icon: Settings2, label: 'إجراء على النظام' },
};

const card = computed(() => props.tracked.card);
const isQuestion = computed(() => card.value.kind === 'question');

const meta = computed(() => {
    if (card.value.kind !== 'approval') {
        return { icon: MessageCircleQuestion, label: 'سؤال من المساعد' };
    }

    const base = categoryMeta[card.value.category] ?? { icon: FileText, label: 'تغيير مقترح' };

    return card.value.destructive ? { icon: TriangleAlert, label: base.label + ' — لا يمكن التراجع عنه' } : base;
});

const isPending = computed(() => props.tracked.status === 'pending');

const decisionChip = computed(() => {
    const decision = props.tracked.decision;

    if (decision === null) {
        return null;
    }

    if (typeof decision === 'object' && decision.action === 'reject') {
        return { icon: CircleX, label: isQuestion.value ? 'تم التخطي' : 'مرفوض', tone: 'muted' };
    }

    return { icon: CircleCheck, label: isQuestion.value ? 'تمت الإجابة' : 'تم التأكيد', tone: 'ok' };
});

/** The question card's answer draft. */
const answer = ref('');

const scalar = (value: unknown): value is string | number | boolean =>
    typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean';

/**
 * Editable cards: the scalar arguments become prefilled inputs; non-scalar
 * values stay read-only JSON below. Edited values resubmit as an edit
 * decision through the same schema-validated tool path.
 */
const editableEntries = computed(() => {
    if (card.value.kind !== 'approval' || !card.value.editable) {
        return [];
    }

    return Object.entries(card.value.arguments).filter(([, value]) => scalar(value));
});

const complexEntries = computed(() => {
    if (card.value.kind !== 'approval') {
        return [];
    }

    return Object.entries(card.value.arguments).filter(([, value]) => !scalar(value) && value !== null && value !== undefined);
});

const edits = reactive<Record<string, string>>({});

for (const [key, value] of editableEntries.value) {
    edits[key] = String(value);
}

const confirm = (): void => {
    if (card.value.kind !== 'approval') {
        return;
    }

    const changed = editableEntries.value.some(([key, value]) => edits[key] !== String(value));

    if (!changed) {
        emit('decide', 'approve');

        return;
    }

    // Preserve every original argument; overlay the edited scalars with
    // their original types where the text still parses as one.
    const merged: Record<string, unknown> = { ...card.value.arguments };

    for (const [key, original] of editableEntries.value) {
        const raw = edits[key];

        if (typeof original === 'number' && raw.trim() !== '' && !Number.isNaN(Number(raw))) {
            merged[key] = Number(raw);
        } else if (typeof original === 'boolean') {
            merged[key] = raw === 'true' || raw === '1';
        } else {
            merged[key] = raw;
        }
    }

    emit('decide', { action: 'edit', arguments: merged });
};

const submitAnswer = (): void => {
    if (card.value.kind !== 'question' || answer.value.trim() === '') {
        return;
    }

    emit('decide', { action: 'edit', arguments: { question: card.value.question, answer: answer.value.trim() } });
};
</script>

<template>
    <div
        class="rounded-lg border bg-background p-3 shadow-sm"
        :class="card.kind === 'approval' && card.destructive ? 'border-destructive/40' : 'border-border'"
    >
        <div class="flex items-start gap-2">
            <span
                class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full"
                :class="card.kind === 'approval' && card.destructive ? 'bg-destructive/10 text-destructive' : 'bg-muted text-muted-foreground'"
            >
                <component :is="meta.icon" class="size-3.5" />
            </span>
            <div class="min-w-0 flex-1 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-muted-foreground">{{ meta.label }}</span>
                    <span
                        v-if="decisionChip"
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="
                            decisionChip.tone === 'ok' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-muted text-muted-foreground'
                        "
                    >
                        <component :is="decisionChip.icon" class="size-3" />
                        {{ decisionChip.label }}
                    </span>
                </div>

                <!-- Question card -->
                <template v-if="card.kind === 'question'">
                    <p class="text-sm font-medium">{{ card.question }}</p>

                    <div v-if="isPending" class="flex items-end gap-2 pt-1">
                        <textarea
                            v-model="answer"
                            dir="rtl"
                            rows="1"
                            maxlength="1000"
                            placeholder="اكتب إجابتك…"
                            aria-label="إجابتك على سؤال المساعد"
                            class="max-h-28 min-h-9 flex-1 resize-y rounded-lg border border-input bg-background px-3 py-1.5 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring"
                            @keydown.enter.exact.prevent="submitAnswer"
                        />
                        <Button size="sm" class="gap-1.5" :disabled="answer.trim() === ''" @click="submitAnswer">
                            <Check class="size-3.5" />
                            إرسال
                        </Button>
                        <Button size="sm" variant="outline" class="gap-1.5" @click="emit('decide', { action: 'reject' })">
                            <X class="size-3.5" />
                            تخطّي
                        </Button>
                    </div>
                </template>

                <!-- Approval card -->
                <template v-else>
                    <p class="text-sm font-medium">{{ card.reason ?? card.title }}</p>

                    <!-- Editable payload: adjust before confirming. -->
                    <dl v-if="isPending && editableEntries.length > 0" class="space-y-1.5 text-xs">
                        <div v-for="[key] in editableEntries" :key="key" class="flex items-center gap-1.5">
                            <dt class="shrink-0 font-medium text-muted-foreground" dir="ltr">{{ key }}:</dt>
                            <dd class="min-w-0 flex-1">
                                <input
                                    v-model="edits[key]"
                                    type="text"
                                    :aria-label="key"
                                    class="w-full rounded-md border border-input bg-background px-2 py-1 text-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                            </dd>
                        </div>
                    </dl>

                    <!-- Read-only view once decided, or for one-click cards. -->
                    <dl v-else-if="editableEntries.length > 0 || complexEntries.length > 0" class="space-y-0.5 text-xs text-muted-foreground">
                        <div v-for="[key, value] in [...editableEntries, ...complexEntries]" :key="key" class="flex gap-1.5">
                            <dt class="shrink-0 font-medium" dir="ltr">{{ key }}:</dt>
                            <dd class="min-w-0 break-words">{{ scalar(value) ? String(value) : JSON.stringify(value) }}</dd>
                        </div>
                    </dl>

                    <div
                        v-if="isPending && complexEntries.length > 0 && editableEntries.length > 0"
                        class="space-y-0.5 text-xs text-muted-foreground"
                    >
                        <div v-for="[key, value] in complexEntries" :key="key" class="flex gap-1.5">
                            <span class="shrink-0 font-medium" dir="ltr">{{ key }}:</span>
                            <span class="min-w-0 break-words">{{ JSON.stringify(value) }}</span>
                        </div>
                    </div>

                    <div v-if="isPending" class="flex items-center gap-2 pt-1">
                        <Button size="sm" class="gap-1.5" :variant="card.destructive ? 'destructive' : 'default'" @click="confirm">
                            <Check class="size-3.5" />
                            تأكيد
                        </Button>
                        <Button size="sm" variant="outline" class="gap-1.5" @click="emit('decide', { action: 'reject' })">
                            <X class="size-3.5" />
                            رفض
                        </Button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
