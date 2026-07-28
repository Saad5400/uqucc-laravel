<script setup lang="ts">
/**
 * Queue an AI generation for a chosen day. Defaults to the first day with
 * nothing scheduled, so filling a buffer of upcoming questions is the happy
 * path; picking a day that already has a `ready` question re-rolls it, and a
 * posted day is refused outright.
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
import type { QueuedDay, Topic } from './types';

/** 'auto' means let the author pick; otherwise the chosen active topic id as a string. */
const AUTO_TOPIC = 'auto';

const props = defineProps<{
    open: boolean;
    topics: Topic[];
    /** Every day from today on that already has a question. */
    upcoming: QueuedDay[];
    today: string;
    /** The first day with nothing scheduled — where generation lands by default. */
    defaultDate: string;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const processing = ref(false);
const date = ref(props.defaultDate);
const topicId = ref(AUTO_TOPIC);
const error = ref<string | null>(null);

watch(
    () => props.open,
    (open) => {
        if (open) {
            date.value = props.defaultDate;
            topicId.value = AUTO_TOPIC;
            error.value = null;
        }
    },
    { immediate: true },
);

const activeTopics = computed(() => props.topics.filter((topic) => topic.is_active));

/** The question already scheduled on the chosen day, if any. */
const occupant = computed(() => props.upcoming.find((day) => day.quiz_date === date.value) ?? null);

const willReplace = computed(() => occupant.value?.status === 'ready');

const blockedReason = computed(() => {
    if (date.value === '') {
        return 'اختر تاريخاً أولاً.';
    }

    if (date.value < props.today) {
        return 'لا يمكن توليد سؤال ليوم مضى — النشر التلقائي يأخذ سؤال اليوم فقط.';
    }

    if (occupant.value !== null && occupant.value.status !== 'ready') {
        return 'سؤال هذا اليوم منشور بالفعل — لا يمكن إعادة توليده.';
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
        '/manage/quiz/generate',
        { date: date.value, topic_id: topicId.value === AUTO_TOPIC ? null : Number(topicId.value) },
        {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
            onError: (errors) => {
                error.value = errors.generate ?? errors.date ?? errors.topic_id ?? null;
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
                <DialogTitle>توليد سؤال بالذكاء الاصطناعي</DialogTitle>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <p v-if="error" class="text-sm text-destructive-foreground">{{ error }}</p>

                <div class="space-y-2">
                    <Label for="generate-date">اليوم المستهدف</Label>
                    <Input id="generate-date" v-model="date" type="date" dir="ltr" class="text-start tabular-nums" :min="today" />
                    <p v-if="date" class="text-xs text-muted-foreground">{{ formatWeekdayDate(date) }}</p>
                </div>

                <p v-if="willReplace" class="rounded-lg border border-border bg-muted/50 p-3 text-sm">
                    يوجد سؤال «بانتظار النشر» في هذا اليوم — التوليد سيستبدله بسؤال جديد. لن يُحذف السؤال الحالي إلا بعد نجاح التوليد.
                </p>
                <p v-else-if="blockedReason" class="rounded-lg border border-border bg-muted/50 p-3 text-sm">{{ blockedReason }}</p>

                <div class="space-y-2">
                    <Label for="generate-topic">الموضوع</Label>
                    <Select id="generate-topic" v-model="topicId">
                        <SelectTrigger class="w-full" aria-label="موضوع السؤال">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="AUTO_TOPIC">موضوع عشوائي (يختاره النظام)</SelectItem>
                            <SelectItem v-for="topic in activeTopics" :key="topic.id" :value="String(topic.id)">
                                {{ topic.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        اترك «موضوع عشوائي» ليختار النظام الموضوع الأقل استخداماً كالمعتاد، أو اختر موضوعاً محدداً لتوليد سؤال منه.
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
