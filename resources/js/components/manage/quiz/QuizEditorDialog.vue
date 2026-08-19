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

/**
 * The readable-text length of the HTML question, so the counter matches the
 * server cap (which measures text, not markup).
 */
const questionTextLength = computed(() => {
    if (typeof document === 'undefined') {
        return form.question.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim().length;
    }

    const el = document.createElement('div');
    el.innerHTML = form.question;

    return (el.textContent ?? '').replace(/\s+/g, ' ').trim().length;
});

/** The topic name shown in the live preview's header, when one is chosen. */
const selectedTopicName = computed(() => {
    if (form.quiz_topic_id === NO_TOPIC) {
        return null;
    }

    return props.topics.find((topic) => String(topic.id) === form.quiz_topic_id)?.name ?? null;
});
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
                        <Label for="quiz-question">السؤال (يُرسم في صورة)</Label>
                        <CharCount :count="questionTextLength" :max="limits.question" />
                    </div>
                    <Textarea
                        id="quiz-question"
                        v-model="form.question"
                        dir="auto"
                        rows="7"
                        class="font-mono text-sm"
                        placeholder="&lt;p dir=&quot;rtl&quot;&gt;المقدمة التعليمية…&lt;/p&gt;&#10;&lt;pre dir=&quot;ltr&quot;&gt;&lt;code&gt;print(2 ** 3)&lt;/code&gt;&lt;/pre&gt;&#10;&lt;p dir=&quot;rtl&quot;&gt;نص السؤال؟&lt;/p&gt;"
                        :aria-invalid="form.errors.question ? true : undefined"
                    />
                    <p v-if="form.errors.question" class="text-sm text-destructive-foreground">{{ form.errors.question }}</p>
                    <p v-else class="text-xs text-muted-foreground">
                        HTML بسيط: افقرة عربية داخل <code dir="ltr">&lt;p dir="rtl"&gt;</code>، والكود في سطر مستقل داخل
                        <code dir="ltr">&lt;pre dir="ltr"&gt;</code>. لا تخلط اتجاهين في سطر واحد. المسموح: p, br, pre, code, strong, b, em, i,
                        span, ul, ol, li, h3, h4 والسمة dir فقط.
                    </p>
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
                    <Label>معاينة الصورة</Label>
                    <div class="quiz-preview" dir="rtl">
                        <div class="quiz-preview-header">
                            <div class="quiz-preview-brand"><span class="quiz-preview-mark">؟</span> سؤال اليوم</div>
                            <span v-if="selectedTopicName" class="quiz-preview-topic">{{ selectedTopicName }}</span>
                        </div>
                        <div v-if="form.question.trim()" class="quiz-preview-content" v-html="form.question"></div>
                        <p v-else class="quiz-preview-empty">اكتب السؤال بالأعلى لتظهر معاينته هنا.</p>
                        <div class="quiz-preview-options">
                            <div
                                v-for="index in [0, 1, 2, 3]"
                                :key="index"
                                class="quiz-preview-option"
                                :class="{ 'quiz-preview-option-correct': Number(form.correct_option) === index }"
                            >
                                <span class="quiz-preview-number">{{ index + 1 }}</span>
                                <span class="quiz-preview-text">{{ form.options[index] || '—' }}</span>
                            </div>
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">هكذا تظهر الصورة تقريباً في المجموعة (الخيار المحدد بالأخضر هو الصحيح، ولا يظهر في الصورة المنشورة).</p>
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

<style scoped>
/*
 * A faithful-enough preview of the posted image card (App\Services\Quiz\
 * QuizImageRenderer + resources/views/quiz/question-image.blade.php), so an
 * author sees the direction handling and layout while typing. Colours are
 * fixed to the card's own palette rather than the panel theme, so the preview
 * reads the same in light or dark.
 */
.quiz-preview {
    background: #1b1f27;
    border: 1px solid rgba(255, 255, 255, 0.09);
    border-radius: 18px;
    padding: 22px 24px;
    color: #f4f6f8;
    font-size: 15px;
    line-height: 1.85;
}

.quiz-preview-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 14px;
    margin-bottom: 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.09);
}

.quiz-preview-brand {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    font-size: 16px;
}

.quiz-preview-mark {
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: linear-gradient(140deg, #38a7bb, #2b8598);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
}

.quiz-preview-topic {
    font-size: 12px;
    font-weight: 600;
    color: #38a7bb;
    background: rgba(56, 167, 187, 0.14);
    border: 1px solid rgba(56, 167, 187, 0.28);
    padding: 4px 12px;
    border-radius: 999px;
}

.quiz-preview-empty {
    color: #9aa2ad;
    font-size: 13px;
}

.quiz-preview-content :deep(p) {
    margin-bottom: 8px;
}

.quiz-preview-content :deep(pre) {
    font-family: ui-monospace, 'SFMono-Regular', 'Consolas', monospace;
    background: #0c1017;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 10px;
    padding: 12px 14px;
    margin: 12px 0;
    direction: ltr;
    text-align: left;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 13px;
}

.quiz-preview-content :deep(code) {
    font-family: ui-monospace, 'SFMono-Regular', 'Consolas', monospace;
}

.quiz-preview-options {
    margin-top: 16px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}

.quiz-preview-option {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.09);
    border-radius: 10px;
    padding: 8px 10px;
    font-size: 13px;
    min-width: 0;
}

.quiz-preview-option-correct {
    border-color: rgba(34, 197, 94, 0.7);
    background: rgba(34, 197, 94, 0.1);
}

.quiz-preview-number {
    flex-shrink: 0;
    width: 24px;
    height: 24px;
    border-radius: 7px;
    background: rgba(56, 167, 187, 0.14);
    border: 1px solid rgba(56, 167, 187, 0.4);
    color: #38a7bb;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 12px;
    font-variant-numeric: tabular-nums;
}

.quiz-preview-text {
    unicode-bidi: plaintext;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}
</style>
