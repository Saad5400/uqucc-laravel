<script setup lang="ts">
/**
 * Queue an AI generation for a chosen day. Defaults to the first day with
 * nothing queued, so filling a week ahead is the happy path; picking a day
 * that already holds a `ready` poll re-rolls it, and a posted day is refused.
 */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatWeekdayDate } from '@/lib/formatters';
import { router } from '@inertiajs/vue3';
import { Loader2, Sparkles } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import type { QueuedPoll, Theme } from './types';

/** 'auto' means let the rotation pick; otherwise the chosen theme's value. */
const AUTO_THEME = 'auto';

const props = defineProps<{
    open: boolean;
    themes: Theme[];
    /** Every day from today on that already has a poll. */
    upcoming: QueuedPoll[];
    today: string;
    /** The first day with nothing queued — where generation lands by default. */
    defaultDate: string;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const processing = ref(false);
const date = ref(props.defaultDate);
const theme = ref(AUTO_THEME);
const error = ref<string | null>(null);

watch(
    () => props.open,
    (open) => {
        if (open) {
            date.value = props.defaultDate;
            theme.value = AUTO_THEME;
            error.value = null;
        }
    },
    { immediate: true },
);

/** The poll already queued on the chosen day, if any. */
const occupant = computed(() => props.upcoming.find((poll) => poll.poll_date === date.value) ?? null);

const willReplace = computed(() => occupant.value?.status === 'ready');

const blockedReason = computed(() => {
    if (date.value === '') {
        return 'اختر تاريخاً أولاً.';
    }

    if (date.value < props.today) {
        return 'لا يمكن توليد استطلاع ليوم مضى — النشر التلقائي يأخذ استطلاع اليوم فقط.';
    }

    if (occupant.value !== null && occupant.value.status !== 'ready') {
        return 'استطلاع هذا اليوم منشور بالفعل — لا يمكن إعادة توليده.';
    }

    return null;
});

function submit(): void {
    if (blockedReason.value !== null) {
        return;
    }

    processing.value = true;
    error.value = null;

    router.post(
        '/manage/polls/generate',
        { date: date.value, theme: theme.value === AUTO_THEME ? null : theme.value },
        {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
            onError: (errors) => {
                error.value = errors.generate ?? errors.date ?? errors.theme ?? null;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>توليد استطلاع بالذكاء الاصطناعي</DialogTitle>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <p v-if="error" class="text-sm text-destructive-foreground">{{ error }}</p>

                <div class="space-y-2">
                    <Label for="generate-poll-date">اليوم المستهدف</Label>
                    <Input id="generate-poll-date" v-model="date" type="date" dir="ltr" class="text-start tabular-nums" :min="today" />
                    <p v-if="date" class="text-xs text-muted-foreground">{{ formatWeekdayDate(date) }}</p>
                </div>

                <p v-if="willReplace" class="rounded-lg border border-border bg-muted/50 p-3 text-sm">
                    يوجد استطلاع «بانتظار النشر» في هذا اليوم — التوليد سيستبدله باستطلاع جديد. لن يُحذف الحالي إلا بعد نجاح التوليد.
                </p>
                <p v-else-if="blockedReason" class="rounded-lg border border-border bg-muted/50 p-3 text-sm">{{ blockedReason }}</p>

                <div class="space-y-2">
                    <Label for="generate-poll-theme">الزاوية</Label>
                    <Select id="generate-poll-theme" v-model="theme">
                        <SelectTrigger class="w-full" aria-label="زاوية الاستطلاع">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="AUTO_THEME">زاوية بالتناوب (يختارها النظام)</SelectItem>
                            <SelectItem v-for="option in themes" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        اترك «بالتناوب» ليأخذ النظام الزاوية التي طال انتظارها — وهو ما يمنع الطابور من أن يصير شهراً كاملاً عن المحررات.
                    </p>
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="emit('update:open', false)">إلغاء</Button>
                    <Button type="submit" :disabled="processing || blockedReason !== null" :title="blockedReason ?? undefined">
                        <Loader2 v-if="processing" class="size-4 animate-spin" />
                        <Sparkles v-else class="size-4" />
                        {{ willReplace ? 'إعادة التوليد' : 'توليد' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
