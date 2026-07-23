<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { TagsInput, TagsInputInput, TagsInputItem, TagsInputItemDelete, TagsInputItemText } from '@/components/ui/tags-input';
import { Textarea } from '@/components/ui/textarea';
import { arabicCount } from '@/lib/arabic';
import { formatDateTime, formatRelativeTime, formatShortDate } from '@/lib/formatters';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, EllipsisVertical, Loader2, Pencil, Plus, Sparkles, Trash2, Trophy } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

interface QuizSettingsValues {
    enabled: boolean;
    reminders_enabled: boolean;
    chat_ids: string[];
}

interface GroupChat {
    chat_id: string;
    title: string | null;
}

interface Topic {
    id: number;
    name: string;
    prompt_hint: string | null;
    is_spotlight: boolean;
    is_active: boolean;
    last_used_at: string | null;
}

interface Quiz {
    id: number;
    quiz_date: string;
    question: string;
    body: string | null;
    options: string[];
    correct_option: number;
    explanation: string | null;
    hint: string | null;
    status: 'ready' | 'posted' | 'closed';
    topic: string | null;
    posted_at: string | null;
    answers_count: number;
    correct_answers_count: number;
}

interface Player {
    id: number;
    name: string;
    username: string | null;
    points: number;
    current_streak: number;
    answers_count: number;
}

const props = defineProps<{
    settings: QuizSettingsValues;
    groupChats: GroupChat[];
    topics: Topic[];
    quizzes: Quiz[];
    hasTodayQuiz: boolean;
    weeklyTop: Player[];
    allTimeTop: Player[];
}>();

const page = usePage();
const pageErrors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

const statusBadges: Record<Quiz['status'], { label: string; variant: 'secondary' | 'default' | 'outline' }> = {
    ready: { label: 'بانتظار النشر', variant: 'secondary' },
    posted: { label: 'منشور الآن', variant: 'default' },
    closed: { label: 'مغلق', variant: 'outline' },
};

/* ------------------------------------------------------------------ */
/* Generate today's question on demand                                 */
/* ------------------------------------------------------------------ */

const generating = ref(false);

function generateNow(): void {
    generating.value = true;

    router.post(
        '/manage/quiz/generate',
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                generating.value = false;
            },
        },
    );
}

/* ------------------------------------------------------------------ */
/* Edit / delete a not-yet-posted quiz                                 */
/* ------------------------------------------------------------------ */

const editingQuiz = ref<Quiz | null>(null);

const quizForm = useForm({
    question: '',
    body: '' as string | null,
    options: ['', '', '', ''],
    correct_option: '0',
    explanation: '' as string | null,
});

function openQuizEditor(quiz: Quiz): void {
    editingQuiz.value = quiz;
    quizForm.clearErrors();
    quizForm.question = quiz.question;
    quizForm.body = quiz.body ?? '';
    quizForm.options = [...quiz.options];
    quizForm.correct_option = String(quiz.correct_option);
    quizForm.explanation = quiz.explanation ?? '';
}

function submitQuizEditor(): void {
    if (!editingQuiz.value) {
        return;
    }

    quizForm
        .transform((data) => ({
            ...data,
            correct_option: Number(data.correct_option),
            body: data.body === '' ? null : data.body,
            explanation: data.explanation === '' ? null : data.explanation,
        }))
        .put(`/manage/quiz/quizzes/${editingQuiz.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                editingQuiz.value = null;
            },
        });
}

/** First error for the options array, including per-element errors like `options.2`. */
const optionsError = computed(() => {
    const errors = quizForm.errors as Record<string, string>;
    const key = Object.keys(errors).find((errorKey) => errorKey === 'options' || errorKey.startsWith('options.'));

    return key ? errors[key] : null;
});

const deletingQuiz = ref<Quiz | null>(null);
const deleteProcessing = ref(false);

function deleteQuiz(): void {
    if (!deletingQuiz.value) {
        return;
    }

    deleteProcessing.value = true;

    router.delete(`/manage/quiz/quizzes/${deletingQuiz.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deletingQuiz.value = null;
        },
        onFinish: () => {
            deleteProcessing.value = false;
        },
    });
}

/* ------------------------------------------------------------------ */
/* Topics: add / edit dialog (shared form), toggle, delete             */
/* ------------------------------------------------------------------ */

const topicDialogOpen = ref(false);
const editingTopic = ref<Topic | null>(null);

const topicForm = useForm({
    name: '',
    prompt_hint: '' as string | null,
    is_spotlight: false,
    is_active: true,
});

function openTopicDialog(topic: Topic | null): void {
    editingTopic.value = topic;
    topicForm.clearErrors();
    topicForm.name = topic?.name ?? '';
    topicForm.prompt_hint = topic?.prompt_hint ?? '';
    topicForm.is_spotlight = topic?.is_spotlight ?? false;
    topicForm.is_active = topic?.is_active ?? true;
    topicDialogOpen.value = true;
}

function submitTopicDialog(): void {
    const form = topicForm.transform((data) => ({
        ...data,
        prompt_hint: data.prompt_hint === '' ? null : data.prompt_hint,
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            topicDialogOpen.value = false;
        },
    };

    if (editingTopic.value) {
        form.put(`/manage/quiz/topics/${editingTopic.value.id}`, options);
    } else {
        form.post('/manage/quiz/topics', options);
    }
}

const togglingTopicId = ref<number | null>(null);

function toggleTopic(topic: Topic, value: boolean): void {
    togglingTopicId.value = topic.id;

    router.put(
        `/manage/quiz/topics/${topic.id}`,
        {
            name: topic.name,
            prompt_hint: topic.prompt_hint,
            is_spotlight: topic.is_spotlight,
            is_active: value,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                togglingTopicId.value = null;
            },
        },
    );
}

const deletingTopic = ref<Topic | null>(null);
const topicDeleteProcessing = ref(false);

function deleteTopic(): void {
    if (!deletingTopic.value) {
        return;
    }

    topicDeleteProcessing.value = true;

    router.delete(`/manage/quiz/topics/${deletingTopic.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deletingTopic.value = null;
        },
        onFinish: () => {
            topicDeleteProcessing.value = false;
        },
    });
}

/* ------------------------------------------------------------------ */
/* Settings                                                            */
/* ------------------------------------------------------------------ */

const settingsForm = useForm({
    enabled: props.settings.enabled,
    reminders_enabled: props.settings.reminders_enabled,
    chat_ids: [...props.settings.chat_ids],
});

function submitSettings(): void {
    settingsForm.put('/manage/quiz/settings', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => settingsForm.defaults(),
    });
}

function toggleChat(chatId: string): void {
    settingsForm.chat_ids = settingsForm.chat_ids.includes(chatId)
        ? settingsForm.chat_ids.filter((id) => id !== chatId)
        : [...settingsForm.chat_ids, chatId];
}

/** First error for the chat ids field, including per-element errors like `chat_ids.0`. */
const chatIdsError = computed(() => {
    const errors = settingsForm.errors as Record<string, string>;
    const key = Object.keys(errors).find((errorKey) => errorKey === 'chat_ids' || errorKey.startsWith('chat_ids.'));

    return key ? errors[key] : null;
});

const configured = computed(() => props.settings.enabled && props.settings.chat_ids.length > 0);
</script>

<template>
    <Head title="سؤال اليوم" />
    <PageHeader title="سؤال اليوم" description="سؤال يومي بالذكاء الاصطناعي يُنشر في مجموعة التليجرام، مع نقاط وسلاسل أيام ولوحة متصدرين" />

    <div class="space-y-6">
        <div v-if="!configured" class="rounded-lg border border-border bg-muted/50 p-4 text-sm">
            سؤال اليوم غير مفعّل بعد — فعّله وحدد المجموعة المستهدفة من بطاقة «الإعدادات» أسفل الصفحة ليبدأ النشر التلقائي.
        </div>

        <!-- Questions -->
        <Card>
            <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                <CardTitle class="text-lg">الأسئلة</CardTitle>
                <div class="flex items-center gap-2">
                    <p v-if="hasTodayQuiz" class="text-xs text-muted-foreground">سؤال اليوم موجود</p>
                    <Button size="sm" :disabled="hasTodayQuiz || generating" @click="generateNow">
                        <Loader2 v-if="generating" class="size-4 animate-spin" />
                        <Sparkles v-else class="size-4" />
                        توليد سؤال اليوم
                    </Button>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <p v-if="pageErrors.generate" class="text-sm text-destructive-foreground">{{ pageErrors.generate }}</p>
                <p v-if="pageErrors.quiz" class="text-sm text-destructive-foreground">{{ pageErrors.quiz }}</p>

                <EmptyState
                    v-if="!quizzes.length"
                    :icon="Sparkles"
                    title="لا توجد أسئلة بعد"
                    description="يُولَّد سؤال جديد تلقائياً كل يوم من أحد المواضيع أدناه، ويمكنك توليد سؤال اليوم يدوياً من الزر أعلاه. راجع السؤال وعدّله قبل موعد النشر."
                />

                <ul v-else class="overflow-hidden rounded-lg border border-border">
                    <li v-for="quiz in quizzes" :key="quiz.id" class="space-y-2 border-b border-border p-3 last:border-b-0">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1 space-y-1">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                    <span class="tabular-nums">{{ formatShortDate(quiz.quiz_date) }}</span>
                                    <Badge :variant="statusBadges[quiz.status].variant">{{ statusBadges[quiz.status].label }}</Badge>
                                    <Badge v-if="quiz.topic" variant="outline">{{ quiz.topic }}</Badge>
                                    <span v-if="quiz.status !== 'ready'">
                                        {{ arabicCount(quiz.answers_count, { singular: 'إجابة', dual: 'إجابتان', plural: 'إجابات' }) }} — منها
                                        {{ quiz.correct_answers_count }} صحيحة
                                    </span>
                                </div>
                                <p class="font-medium">{{ quiz.question }}</p>
                            </div>

                            <DropdownMenu v-if="quiz.status === 'ready'">
                                <DropdownMenuTrigger as-child>
                                    <Button variant="ghost" size="icon" aria-label="إجراءات السؤال">
                                        <EllipsisVertical />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem @select="openQuizEditor(quiz)">
                                        <Pencil />
                                        تعديل
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem variant="destructive" @select="deletingQuiz = quiz">
                                        <Trash2 />
                                        حذف
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>

                        <pre
                            v-if="quiz.body"
                            dir="ltr"
                            class="overflow-x-auto rounded-md border border-border bg-muted/40 p-2 text-start font-mono text-xs whitespace-pre-wrap"
                            >{{ quiz.body }}</pre
                        >

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
                    </li>
                </ul>
            </CardContent>
        </Card>

        <!-- Topics -->
        <Card>
            <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                <CardTitle class="text-lg">المواضيع</CardTitle>
                <Button size="sm" variant="outline" @click="openTopicDialog(null)">
                    <Plus class="size-4" />
                    إضافة موضوع
                </Button>
            </CardHeader>
            <CardContent class="space-y-4">
                <EmptyState
                    v-if="!topics.length"
                    :icon="Plus"
                    title="لا توجد مواضيع بعد"
                    description="أضف مواضيع يختار الذكاء الاصطناعي منها سؤال كل يوم — مواضيع أساسية مشتركة لكل التخصصات، ومواضيع «يوم التخصص» تُطرح يوم الأربعاء فقط."
                />

                <ul v-else class="overflow-hidden rounded-lg border border-border">
                    <li v-for="topic in topics" :key="topic.id" class="flex items-center gap-3 border-b border-border p-3 last:border-b-0">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-medium" :class="topic.is_active ? '' : 'text-muted-foreground line-through'">{{ topic.name }}</span>
                                <Badge v-if="topic.is_spotlight" variant="secondary">يوم التخصص</Badge>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                <span v-if="topic.prompt_hint" class="max-w-md truncate">{{ topic.prompt_hint }}</span>
                                <span v-if="topic.last_used_at" :title="formatDateTime(topic.last_used_at)"
                                    >آخر استخدام {{ formatRelativeTime(topic.last_used_at) }}</span
                                >
                                <span v-else>لم يُستخدم بعد</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <Switch
                                :model-value="topic.is_active"
                                :disabled="togglingTopicId === topic.id"
                                :aria-label="`تفعيل موضوع ${topic.name}`"
                                @update:model-value="(value) => toggleTopic(topic, value === true)"
                            />
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button variant="ghost" size="icon" :aria-label="`إجراءات ${topic.name}`">
                                        <EllipsisVertical />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem @select="openTopicDialog(topic)">
                                        <Pencil />
                                        تعديل
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem variant="destructive" @select="deletingTopic = topic">
                                        <Trash2 />
                                        حذف
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <!-- Leaderboards -->
        <div class="grid gap-6 lg:grid-cols-2">
            <Card
                v-for="board in [
                    { key: 'weekly', title: 'متصدرو الأسبوع', players: weeklyTop },
                    { key: 'allTime', title: 'متصدرو كل الأوقات', players: allTimeTop },
                ]"
                :key="board.key"
            >
                <CardHeader>
                    <CardTitle class="text-lg">{{ board.title }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <EmptyState
                        v-if="!board.players.length"
                        :icon="Trophy"
                        title="لا نقاط بعد"
                        description="تُحسب النقاط تلقائياً من إجابات أعضاء المجموعة على السؤال اليومي."
                    />
                    <ol v-else class="space-y-1">
                        <li
                            v-for="(player, index) in board.players"
                            :key="player.id"
                            class="flex items-center gap-3 rounded-md px-2 py-1.5"
                            :class="index < 3 ? 'bg-muted/50' : ''"
                        >
                            <span class="w-6 text-center text-sm text-muted-foreground tabular-nums">{{ index + 1 }}</span>
                            <span class="min-w-0 flex-1 truncate">
                                {{ player.name }}
                                <span v-if="player.username" dir="ltr" class="text-xs text-muted-foreground">@{{ player.username }}</span>
                            </span>
                            <span class="text-xs text-muted-foreground"
                                >🔥 <span class="tabular-nums">{{ player.current_streak }}</span></span
                            >
                            <span class="text-sm font-medium tabular-nums">{{ player.points }}</span>
                        </li>
                    </ol>
                </CardContent>
            </Card>
        </div>

        <!-- Settings -->
        <Card class="max-w-2xl">
            <CardHeader>
                <CardTitle class="text-lg">الإعدادات</CardTitle>
            </CardHeader>
            <CardContent>
                <form class="space-y-6" @submit.prevent="submitSettings">
                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <Label for="quiz-enabled">تفعيل سؤال اليوم</Label>
                            <p class="text-xs text-muted-foreground">
                                عند التفعيل: يُولَّد السؤال فجراً، ويُنشر في المجموعة الساعة 4 عصراً، وتُعلن نتائج الأسبوع مساء الخميس.
                            </p>
                        </div>
                        <Switch id="quiz-enabled" v-model="settingsForm.enabled" />
                    </div>
                    <p v-if="settingsForm.errors.enabled" class="text-sm text-destructive-foreground">{{ settingsForm.errors.enabled }}</p>

                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <Label for="quiz-reminders">تذكيرات المشاركة</Label>
                            <p class="text-xs text-muted-foreground">
                                تذكير لطيف بالردّ على السؤال المفتوح: مرة مساءً إن كانت المشاركة قليلة، ومرة أخيرة قبل الإغلاق مع تلميح. يردّ على
                                رسالة السؤال نفسها كي لا يزعج المجموعة.
                            </p>
                        </div>
                        <Switch id="quiz-reminders" v-model="settingsForm.reminders_enabled" />
                    </div>

                    <div class="space-y-2">
                        <Label for="quiz-chat-ids">المجموعات المستهدفة</Label>
                        <TagsInput id="quiz-chat-ids" v-model="settingsForm.chat_ids" :aria-invalid="chatIdsError ? true : undefined">
                            <TagsInputItem v-for="chatId in settingsForm.chat_ids" :key="chatId" :value="chatId" dir="ltr">
                                <TagsInputItemText />
                                <TagsInputItemDelete class="-m-1.5 p-1.5" :aria-label="`إزالة ${chatId}`" />
                            </TagsInputItem>
                            <TagsInputInput placeholder="أضف معرّف مجموعة…" dir="auto" class="text-start" />
                        </TagsInput>
                        <p class="text-xs text-muted-foreground">
                            يُنشر السؤال نفسه في كل مجموعة، والنقاط واللوحة مشتركة — أول إجابة للعضو في أي مجموعة هي التي تُحتسب. معرّفات المجموعات
                            تبدأ بإشارة سالبة. لمجموعة تستخدم المواضيع (Topics) أضف معرّف الموضوع بعد نقطتين، مثل
                            <span dir="ltr" class="font-mono tabular-nums">-100…:42</span>.
                        </p>
                        <div v-if="groupChats.length" class="flex flex-wrap items-center gap-1.5">
                            <span class="text-xs text-muted-foreground">مجموعات يعرفها البوت:</span>
                            <button
                                v-for="chat in groupChats"
                                :key="chat.chat_id"
                                type="button"
                                class="rounded-full border border-border px-2 py-0.5 text-xs hover:bg-muted"
                                :class="settingsForm.chat_ids.includes(chat.chat_id) ? 'border-primary bg-primary/10' : ''"
                                @click="toggleChat(chat.chat_id)"
                            >
                                {{ chat.title ?? chat.chat_id }}
                            </button>
                        </div>
                        <p v-if="chatIdsError" class="text-sm text-destructive-foreground">{{ chatIdsError }}</p>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <p v-if="!settingsForm.isDirty && !settingsForm.processing" class="text-xs text-muted-foreground">لا توجد تغييرات لحفظها</p>
                        <Button type="submit" :disabled="!settingsForm.isDirty || settingsForm.processing">
                            <Loader2 v-if="settingsForm.processing" class="size-4 animate-spin" />
                            حفظ الإعدادات
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>

    <!-- Edit quiz dialog -->
    <Dialog
        :open="editingQuiz !== null"
        @update:open="
            (value) => {
                if (!value) editingQuiz = null;
            }
        "
    >
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>تعديل السؤال</DialogTitle>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submitQuizEditor">
                <div class="space-y-2">
                    <Label for="quiz-question">السؤال</Label>
                    <Textarea id="quiz-question" v-model="quizForm.question" rows="3" :aria-invalid="quizForm.errors.question ? true : undefined" />
                    <p v-if="quizForm.errors.question" class="text-sm text-destructive-foreground">{{ quizForm.errors.question }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="quiz-body">الكود / المقدمة (اختياري)</Label>
                    <Textarea
                        id="quiz-body"
                        v-model="quizForm.body"
                        dir="ltr"
                        rows="5"
                        class="font-mono text-sm"
                        placeholder="ضع الكود داخل سياج ```py … ``` — يُنشر كرسالة منسّقة فوق التصويت، وتُصبح رسالة التصويت مجرد «اختر إجابتك»."
                        :aria-invalid="quizForm.errors.body ? true : undefined"
                    />
                    <p v-if="quizForm.errors.body" class="text-sm text-destructive-foreground">{{ quizForm.errors.body }}</p>
                    <p v-else class="text-xs text-muted-foreground">يدعم ماركداون. ضع الكود بين ثلاث علامات اقتباس خلفية ليظهر بخط ثابت.</p>
                </div>

                <div class="space-y-2">
                    <Label>الخيارات</Label>
                    <div v-for="index in [0, 1, 2, 3]" :key="index" class="flex items-center gap-2">
                        <span class="w-5 text-center text-sm text-muted-foreground tabular-nums">{{ index + 1 }}</span>
                        <Input v-model="quizForm.options[index]" :aria-label="`الخيار ${index + 1}`" />
                    </div>
                    <p v-if="optionsError" class="text-sm text-destructive-foreground">{{ optionsError }}</p>
                </div>

                <div class="space-y-2">
                    <Label>الإجابة الصحيحة</Label>
                    <Select v-model="quizForm.correct_option">
                        <SelectTrigger class="w-full" aria-label="الإجابة الصحيحة">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="index in [0, 1, 2, 3]" :key="index" :value="String(index)">
                                {{ index + 1 }} — {{ quizForm.options[index] || 'خيار فارغ' }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="quizForm.errors.correct_option" class="text-sm text-destructive-foreground">{{ quizForm.errors.correct_option }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="quiz-explanation">الشرح (يظهر بعد الإجابة)</Label>
                    <Textarea
                        id="quiz-explanation"
                        v-model="quizForm.explanation"
                        rows="2"
                        :aria-invalid="quizForm.errors.explanation ? true : undefined"
                    />
                    <p v-if="quizForm.errors.explanation" class="text-sm text-destructive-foreground">{{ quizForm.errors.explanation }}</p>
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="editingQuiz = null">إلغاء</Button>
                    <Button type="submit" :disabled="quizForm.processing">
                        <Loader2 v-if="quizForm.processing" class="size-4 animate-spin" />
                        حفظ السؤال
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Add / edit topic dialog -->
    <Dialog v-model:open="topicDialogOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ editingTopic ? 'تعديل الموضوع' : 'إضافة موضوع' }}</DialogTitle>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submitTopicDialog">
                <div class="space-y-2">
                    <Label for="topic-name">اسم الموضوع</Label>
                    <Input
                        id="topic-name"
                        v-model="topicForm.name"
                        placeholder="مثال: أساسيات البرمجة"
                        :aria-invalid="topicForm.errors.name ? true : undefined"
                    />
                    <p v-if="topicForm.errors.name" class="text-sm text-destructive-foreground">{{ topicForm.errors.name }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="topic-hint">توجيهات للذكاء الاصطناعي (اختياري)</Label>
                    <Textarea
                        id="topic-hint"
                        v-model="topicForm.prompt_hint"
                        rows="2"
                        placeholder="مثال: ركّز على المفاهيم لا على صيغة لغة معينة"
                        :aria-invalid="topicForm.errors.prompt_hint ? true : undefined"
                    />
                    <p v-if="topicForm.errors.prompt_hint" class="text-sm text-destructive-foreground">{{ topicForm.errors.prompt_hint }}</p>
                </div>

                <label class="flex items-start gap-2">
                    <Checkbox v-model="topicForm.is_spotlight" class="mt-0.5" />
                    <span class="space-y-1">
                        <span class="block text-sm font-medium">موضوع «يوم التخصص»</span>
                        <span class="block text-xs text-muted-foreground"
                            >مواضيع تخصصية أعمق تُطرح يوم الأربعاء فقط — بقية الأيام للمواضيع الأساسية المشتركة.</span
                        >
                    </span>
                </label>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="topicDialogOpen = false">إلغاء</Button>
                    <Button type="submit" :disabled="topicForm.processing">
                        <Loader2 v-if="topicForm.processing" class="size-4 animate-spin" />
                        {{ editingTopic ? 'حفظ الموضوع' : 'إضافة الموضوع' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        :open="deletingQuiz !== null"
        title="حذف السؤال"
        destructive
        confirm-label="حذف"
        :processing="deleteProcessing"
        @confirm="deleteQuiz"
        @update:open="
            (value) => {
                if (!value) deletingQuiz = null;
            }
        "
    >
        سيُحذف هذا السؤال نهائياً. يمكنك بعدها توليد سؤال جديد لليوم نفسه.
    </ConfirmDialog>

    <ConfirmDialog
        :open="deletingTopic !== null"
        title="حذف الموضوع"
        destructive
        confirm-label="حذف"
        :processing="topicDeleteProcessing"
        @confirm="deleteTopic"
        @update:open="
            (value) => {
                if (!value) deletingTopic = null;
            }
        "
    >
        <template v-if="deletingTopic">
            سيُحذف موضوع «{{ deletingTopic.name }}» — الأسئلة السابقة المولّدة منه تبقى كما هي. إن أردت إيقافه مؤقتاً فقط، استخدم مفتاح التفعيل بدلاً
            من الحذف.
        </template>
    </ConfirmDialog>
</template>
