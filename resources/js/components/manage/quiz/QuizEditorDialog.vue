<script setup lang="ts">
/**
 * Write a question by hand or edit a not-yet-posted one — the same form on
 * the front door and in the archive. Every column the bot sends is here, with
 * Telegram's caps shown live rather than sprung as a validation error.
 */
import CharCount from '@/components/manage/CharCount.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/vue3';
import { CheckCircle2, Loader2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import type { Limits, Quiz, Topic } from './types';

/** The "no topic" sentinel — Select cannot hold an empty string value. */
const NO_TOPIC = 'none';

const props = defineProps<{
    open: boolean;
    /** The question being edited, or null to write a new one. */
    quiz: Quiz | null;
    topics: Topic[];
    limits: Limits;
    today: string;
    /** The day a new question lands on: the first one with nothing scheduled. */
    defaultDate: string;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

/**
 * Optional text is held as an empty string (the textareas' natural value);
 * the server normalizes blanks back to null on save.
 */
const form = useForm({
    quiz_topic_id: NO_TOPIC,
    quiz_date: '',
    question: '',
    body: '',
    options: ['', '', '', ''],
    correct_option: '0',
    explanation: '',
    hint: '',
    obvious_hint: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        const quiz = props.quiz;

        form.clearErrors();
        form.quiz_topic_id = quiz?.quiz_topic_id ? String(quiz.quiz_topic_id) : NO_TOPIC;
        form.quiz_date = quiz?.quiz_date ?? props.defaultDate;
        form.question = quiz?.question ?? '';
        form.body = quiz?.body ?? '';
        form.options = quiz ? [...quiz.options] : ['', '', '', ''];
        form.correct_option = String(quiz?.correct_option ?? 0);
        form.explanation = quiz?.explanation ?? '';
        form.hint = quiz?.hint ?? '';
        form.obvious_hint = quiz?.obvious_hint ?? '';
    },
    { immediate: true },
);

function submit(): void {
    const submission = form.transform((data) => ({
        ...data,
        quiz_topic_id: data.quiz_topic_id === NO_TOPIC ? null : Number(data.quiz_topic_id),
        correct_option: Number(data.correct_option),
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.quiz) {
        submission.put(`/manage/quiz/quizzes/${props.quiz.id}`, options);
    } else {
        submission.post('/manage/quiz/quizzes', options);
    }
}

/** The "already posted" rejection, raised against the whole question rather than a field. */
const lockError = computed(() => (form.errors as Record<string, string>).quiz ?? null);

/** First error for the options array, including per-element errors like `options.2`. */
const optionsError = computed(() => {
    const errors = form.errors as Record<string, string>;
    const key = Object.keys(errors).find((errorKey) => errorKey === 'options' || errorKey.startsWith('options.'));

    return key ? errors[key] : null;
});

/** A question dated before today never posts — the poster only looks up today. */
const dateIsPast = computed(() => form.quiz_date !== '' && form.quiz_date < props.today);
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ quiz ? 'تعديل السؤال' : 'كتابة سؤال جديد' }}</DialogTitle>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <p v-if="lockError" class="text-sm text-destructive-foreground">{{ lockError }}</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="quiz-date">تاريخ النشر</Label>
                        <Input
                            id="quiz-date"
                            v-model="form.quiz_date"
                            type="date"
                            dir="ltr"
                            class="text-start tabular-nums"
                            :aria-invalid="form.errors.quiz_date ? true : undefined"
                        />
                        <p v-if="form.errors.quiz_date" class="text-sm text-destructive-foreground">{{ form.errors.quiz_date }}</p>
                        <p v-else-if="dateIsPast" class="text-xs text-destructive-foreground">
                            تاريخ ماضٍ — لن يُنشر هذا السؤال، فالنشر التلقائي يأخذ سؤال اليوم فقط.
                        </p>
                        <p v-else class="text-xs text-muted-foreground">يُنشر سؤال اليوم المحدد تلقائياً الساعة 4 عصراً. لكل يوم سؤال واحد.</p>
                    </div>

                    <div class="space-y-2">
                        <Label for="quiz-topic">الموضوع</Label>
                        <Select id="quiz-topic" v-model="form.quiz_topic_id">
                            <SelectTrigger class="w-full" aria-label="موضوع السؤال">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NO_TOPIC">بدون موضوع</SelectItem>
                                <SelectItem v-for="topic in topics" :key="topic.id" :value="String(topic.id)">
                                    {{ topic.name }}{{ topic.is_active ? '' : ' (معطّل)' }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="form.errors.quiz_topic_id" class="text-sm text-destructive-foreground">{{ form.errors.quiz_topic_id }}</p>
                        <p v-else class="text-xs text-muted-foreground">تصنيف فقط — يظهر بجانب السؤال ويمنع تكرار الموضوع في التوليد التالي.</p>
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <Label for="quiz-question">السؤال</Label>
                        <CharCount :value="form.question" :max="limits.question" />
                    </div>
                    <Textarea id="quiz-question" v-model="form.question" rows="3" :aria-invalid="form.errors.question ? true : undefined" />
                    <p v-if="form.errors.question" class="text-sm text-destructive-foreground">{{ form.errors.question }}</p>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <Label for="quiz-body">الكود / المقدمة (اختياري)</Label>
                        <CharCount :value="form.body" :max="limits.body" />
                    </div>
                    <Textarea
                        id="quiz-body"
                        v-model="form.body"
                        dir="ltr"
                        rows="5"
                        class="font-mono text-sm"
                        placeholder="ضع الكود داخل سياج ```py … ``` — يُنشر كرسالة منسّقة فوق التصويت، وتُصبح رسالة التصويت مجرد «اختر إجابتك»."
                        :aria-invalid="form.errors.body ? true : undefined"
                    />
                    <p v-if="form.errors.body" class="text-sm text-destructive-foreground">{{ form.errors.body }}</p>
                    <p v-else class="text-xs text-muted-foreground">يدعم ماركداون. ضع الكود بين ثلاث علامات اقتباس خلفية ليظهر بخط ثابت.</p>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <Label>الخيارات</Label>
                        <span class="text-xs text-muted-foreground">الحد {{ limits.option }} حرف للخيار</span>
                    </div>
                    <div v-for="index in [0, 1, 2, 3]" :key="index" class="flex items-center gap-2">
                        <span
                            class="flex w-5 shrink-0 justify-center text-sm text-muted-foreground tabular-nums"
                            :title="Number(form.correct_option) === index ? 'الإجابة الصحيحة' : undefined"
                        >
                            <CheckCircle2 v-if="Number(form.correct_option) === index" class="size-4 text-primary" />
                            <template v-else>{{ index + 1 }}</template>
                        </span>
                        <Input
                            v-model="form.options[index]"
                            :aria-label="`الخيار ${index + 1}`"
                            :class="Number(form.correct_option) === index ? 'border-primary' : ''"
                        />
                        <CharCount :value="form.options[index]" :max="limits.option" class="w-14 shrink-0 text-end" />
                    </div>
                    <p v-if="optionsError" class="text-sm text-destructive-foreground">{{ optionsError }}</p>
                </div>

                <div class="space-y-2">
                    <Label>الإجابة الصحيحة</Label>
                    <Select v-model="form.correct_option">
                        <SelectTrigger class="w-full" aria-label="الإجابة الصحيحة">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="index in [0, 1, 2, 3]" :key="index" :value="String(index)">
                                {{ index + 1 }} — {{ form.options[index] || 'خيار فارغ' }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.correct_option" class="text-sm text-destructive-foreground">{{ form.errors.correct_option }}</p>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <Label for="quiz-explanation">الشرح (يظهر بعد الإجابة)</Label>
                        <CharCount :value="form.explanation" :max="limits.explanation" />
                    </div>
                    <Textarea id="quiz-explanation" v-model="form.explanation" rows="2" :aria-invalid="form.errors.explanation ? true : undefined" />
                    <p v-if="form.errors.explanation" class="text-sm text-destructive-foreground">{{ form.errors.explanation }}</p>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <Label for="quiz-hint">🧩 تلميح التذكير (اختياري)</Label>
                        <CharCount :value="form.hint" :max="limits.hint" />
                    </div>
                    <Textarea id="quiz-hint" v-model="form.hint" rows="2" :aria-invalid="form.errors.hint ? true : undefined" />
                    <p v-if="form.errors.hint" class="text-sm text-destructive-foreground">{{ form.errors.hint }}</p>
                    <p v-else class="text-xs text-muted-foreground">
                        يُرسله البوت في منتصف مدة السؤال لتحريك المشاركة — يقرّب الفكرة دون كشف الإجابة. اتركه فارغاً ليتخطى البوت هذا التذكير.
                    </p>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <Label for="quiz-obvious-hint">💡 تلميح آخر فرصة (اختياري)</Label>
                        <CharCount :value="form.obvious_hint" :max="limits.hint" />
                    </div>
                    <Textarea
                        id="quiz-obvious-hint"
                        v-model="form.obvious_hint"
                        rows="2"
                        :aria-invalid="form.errors.obvious_hint ? true : undefined"
                    />
                    <p v-if="form.errors.obvious_hint" class="text-sm text-destructive-foreground">{{ form.errors.obvious_hint }}</p>
                    <p v-else class="text-xs text-muted-foreground">
                        التلميح الأصرح قبل إغلاق السؤال مباشرة. إن تركته فارغاً استُخدم تلميح التذكير بدلاً منه.
                    </p>
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="emit('update:open', false)">إلغاء</Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        {{ quiz ? 'حفظ السؤال' : 'إضافة السؤال' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
