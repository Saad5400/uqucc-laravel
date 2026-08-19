<script setup lang="ts">
/**
 * One daily question rendered exactly as it is everywhere it appears — the
 * front door's prominent "next question up" and every archive row. `prominent`
 * only scales the typography; the content, status vocabulary and lock rules
 * are identical, so the two surfaces can never drift.
 */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { arabicCount } from '@/lib/arabic';
import { formatShortDate, formatTimeOfDay, formatWeekdayDate } from '@/lib/formatters';
import { CheckCircle2, EllipsisVertical, Pencil, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { lockReason, statusBadges, type Quiz } from './types';

const props = withDefaults(
    defineProps<{
        quiz: Quiz;
        prominent?: boolean;
    }>(),
    { prominent: false },
);

const emit = defineEmits<{
    edit: [quiz: Quiz];
    delete: [quiz: Quiz];
}>();

const locked = computed(() => lockReason(props.quiz));
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1 space-y-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                    <span class="tabular-nums" :class="prominent ? 'text-sm font-medium text-foreground' : ''">
                        {{ prominent ? formatWeekdayDate(quiz.quiz_date) : formatShortDate(quiz.quiz_date) }}
                    </span>
                    <Badge :variant="statusBadges[quiz.status].variant">{{ statusBadges[quiz.status].label }}</Badge>
                    <Badge v-if="quiz.post_time && quiz.status === 'ready'" variant="outline" title="موعد نشر خاص بهذا اليوم">
                        {{ formatTimeOfDay(quiz.post_time) }}
                    </Badge>
                    <Badge v-if="quiz.topic" variant="outline">{{ quiz.topic }}</Badge>
                    <span v-if="quiz.status !== 'ready'">
                        {{ arabicCount(quiz.answers_count, { singular: 'إجابة', dual: 'إجابتان', plural: 'إجابات' }) }} — منها
                        <span class="tabular-nums">{{ quiz.correct_answers_count }}</span> صحيحة
                    </span>
                </div>
                <div
                    class="quiz-question"
                    :class="prominent ? 'text-base font-medium' : 'text-sm font-medium'"
                    v-html="quiz.question"
                ></div>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button variant="ghost" size="icon" aria-label="إجراءات السؤال">
                        <EllipsisVertical />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="max-w-72">
                    <p v-if="locked" class="px-2 py-1.5 text-xs text-balance text-muted-foreground">{{ locked }}</p>
                    <DropdownMenuItem :disabled="locked !== null" @select="emit('edit', quiz)">
                        <Pencil />
                        تعديل
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" :disabled="locked !== null" @select="emit('delete', quiz)">
                        <Trash2 />
                        حذف
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <ol class="grid gap-1 text-sm sm:grid-cols-2">
            <li
                v-for="(option, index) in quiz.options"
                :key="index"
                class="flex items-center gap-1.5 rounded-md border border-border px-2 py-1"
                :class="index === quiz.correct_option ? 'border-transparent bg-primary/10' : ''"
            >
                <CheckCircle2 v-if="index === quiz.correct_option" class="size-4 shrink-0 text-primary" />
                <span class="min-w-0 truncate">{{ option }}</span>
            </li>
        </ol>

        <p v-if="quiz.explanation" class="text-xs text-muted-foreground">الشرح: {{ quiz.explanation }}</p>
        <p v-if="quiz.hint" class="text-xs text-muted-foreground">🧩 تلميح التذكير: {{ quiz.hint }}</p>
        <p v-if="quiz.obvious_hint" class="text-xs text-muted-foreground">💡 تلميح آخر فرصة: {{ quiz.obvious_hint }}</p>
    </div>
</template>

<style scoped>
/* The question is a sanitized HTML fragment; give its blocks readable spacing. */
.quiz-question :deep(p) {
    margin-bottom: 0.35rem;
}

.quiz-question :deep(p:last-child) {
    margin-bottom: 0;
}

.quiz-question :deep(pre) {
    direction: ltr;
    text-align: start;
    overflow-x: auto;
    margin: 0.5rem 0;
    border-radius: 0.375rem;
    border: 1px solid var(--border);
    background: color-mix(in oklab, var(--muted) 40%, transparent);
    padding: 0.5rem 0.65rem;
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 0.75rem;
    white-space: pre-wrap;
    word-break: break-word;
}

.quiz-question :deep(code) {
    font-family: var(--font-mono, ui-monospace, monospace);
}
</style>
