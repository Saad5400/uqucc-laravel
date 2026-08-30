<script setup lang="ts">
/**
 * Write an opinion poll by hand, or edit one — authored or generated — that
 * has not gone out yet. Two to ten options and Telegram's caps shown live;
 * a new poll also gets a shelf of ready-made questions, for the admin who
 * would rather pick one than wait on a generation.
 */
import CharCount from '@/components/manage/CharCount.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/vue3';
import { Loader2, Plus, Wand2, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import type { Limits, Poll, Suggestion } from './types';

const props = defineProps<{
    open: boolean;
    /** The poll being edited, or null to write a new one. */
    poll: Poll | null;
    limits: Limits;
    suggestions: Suggestion[];
    today: string;
    /** The day a new poll lands on: the first one with nothing queued. */
    defaultDate: string;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const form = useForm({
    poll_date: '',
    question: '',
    options: ['', ''],
    post_time: '',
});

const showSuggestions = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        const poll = props.poll;

        form.clearErrors();
        form.poll_date = poll?.poll_date ?? props.defaultDate;
        form.question = poll?.question ?? '';
        form.options = poll ? [...poll.options] : ['', ''];
        form.post_time = poll?.post_time ?? '';
        showSuggestions.value = false;
    },
    { immediate: true },
);

function applySuggestion(suggestion: Suggestion): void {
    form.question = suggestion.question;
    form.options = [...suggestion.options];
    showSuggestions.value = false;
}

function addOption(): void {
    if (form.options.length < props.limits.max_options) {
        form.options = [...form.options, ''];
    }
}

function removeOption(index: number): void {
    if (form.options.length > props.limits.min_options) {
        form.options = form.options.filter((_, position) => position !== index);
    }
}

function submit(): void {
    const submission = form.transform((data) => ({
        ...data,
        post_time: data.post_time === '' ? null : data.post_time,
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.poll) {
        submission.put(`/manage/polls/${props.poll.id}`, options);
    } else {
        submission.post('/manage/polls', options);
    }
}

/** The "already posted" rejection, raised against the whole poll rather than a field. */
const lockError = computed(() => (form.errors as Record<string, string>).poll ?? null);

/** First error for the options array, including per-element errors like `options.2`. */
const optionsError = computed(() => {
    const errors = form.errors as Record<string, string>;
    const key = Object.keys(errors).find((errorKey) => errorKey === 'options' || errorKey.startsWith('options.'));

    return key ? errors[key] : null;
});

/** A poll dated before today never posts — the command only looks up today. */
const dateIsPast = computed(() => form.poll_date !== '' && form.poll_date < props.today);
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ poll ? 'تعديل الاستطلاع' : 'استطلاع جديد' }}</DialogTitle>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <p v-if="lockError" class="text-sm text-destructive-foreground">{{ lockError }}</p>

                <div v-if="!poll && suggestions.length" class="space-y-2">
                    <Button type="button" variant="outline" size="sm" class="w-full" @click="showSuggestions = !showSuggestions">
                        <Wand2 class="size-4" />
                        {{ showSuggestions ? 'إخفاء الاقتراحات' : 'ابدأ من اقتراح جاهز' }}
                    </Button>
                    <ul v-if="showSuggestions" class="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-border p-1">
                        <li v-for="suggestion in suggestions" :key="suggestion.question">
                            <button
                                type="button"
                                class="w-full rounded-md px-2 py-1.5 text-start text-sm hover:bg-muted"
                                @click="applySuggestion(suggestion)"
                            >
                                <span class="block">{{ suggestion.question }}</span>
                                <span class="block truncate text-xs text-muted-foreground">{{ suggestion.options.join(' · ') }}</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="poll-date">تاريخ النشر</Label>
                        <Input
                            id="poll-date"
                            v-model="form.poll_date"
                            type="date"
                            dir="ltr"
                            class="text-start tabular-nums"
                            :aria-invalid="form.errors.poll_date ? true : undefined"
                        />
                        <p v-if="form.errors.poll_date" class="text-sm text-destructive-foreground">{{ form.errors.poll_date }}</p>
                        <p v-else-if="dateIsPast" class="text-xs text-destructive-foreground">
                            تاريخ ماضٍ — لن يُنشر هذا الاستطلاع، فالنشر التلقائي يأخذ استطلاع اليوم فقط.
                        </p>
                        <p v-else class="text-xs text-muted-foreground">لكل يوم استطلاع واحد.</p>
                    </div>

                    <div class="space-y-2">
                        <Label for="poll-time">موعد خاص (اختياري)</Label>
                        <Input
                            id="poll-time"
                            v-model="form.post_time"
                            type="time"
                            dir="ltr"
                            class="text-start tabular-nums"
                            :aria-invalid="form.errors.post_time ? true : undefined"
                        />
                        <p v-if="form.errors.post_time" class="text-sm text-destructive-foreground">{{ form.errors.post_time }}</p>
                        <p v-else class="text-xs text-muted-foreground">اتركه فارغاً ليتبع الموعد الافتراضي في الإعدادات.</p>
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <Label for="poll-question">نص الاستطلاع</Label>
                        <CharCount :value="form.question" :max="limits.question" />
                    </div>
                    <Textarea
                        id="poll-question"
                        v-model="form.question"
                        dir="auto"
                        rows="2"
                        placeholder="مثال: ما المحرر الذي تكتب به أكثر؟"
                        :aria-invalid="form.errors.question ? true : undefined"
                    />
                    <p v-if="form.errors.question" class="text-sm text-destructive-foreground">{{ form.errors.question }}</p>
                    <p v-else class="text-xs text-muted-foreground">
                        سؤال رأي بلا إجابة صحيحة — التصويت مجهول، فلا نقاط ولا سلاسل، ولا شيء يخسره من يشارك.
                    </p>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <Label>الخيارات</Label>
                        <span class="text-xs text-muted-foreground tabular-nums">{{ form.options.length }} / {{ limits.max_options }}</span>
                    </div>

                    <div v-for="(option, index) in form.options" :key="index" class="flex items-center gap-2">
                        <Input
                            v-model="form.options[index]"
                            dir="auto"
                            class="text-start"
                            :placeholder="`الخيار ${index + 1}`"
                            :aria-label="`الخيار ${index + 1}`"
                            :aria-invalid="optionsError ? true : undefined"
                        />
                        <CharCount :value="option" :max="limits.option" />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :disabled="form.options.length <= limits.min_options"
                            :title="form.options.length <= limits.min_options ? `الاستطلاع يحتاج ${limits.min_options} خيارين على الأقل` : undefined"
                            :aria-label="`حذف الخيار ${index + 1}`"
                            @click="removeOption(index)"
                        >
                            <X />
                        </Button>
                    </div>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="form.options.length >= limits.max_options"
                        :title="form.options.length >= limits.max_options ? `تيليجرام يقبل ${limits.max_options} خيارات كحد أقصى` : undefined"
                        @click="addOption"
                    >
                        <Plus class="size-4" />
                        إضافة خيار
                    </Button>

                    <p v-if="optionsError" class="text-sm text-destructive-foreground">{{ optionsError }}</p>
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="emit('update:open', false)">إلغاء</Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        {{ poll ? 'حفظ الاستطلاع' : 'إضافة إلى الطابور' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
