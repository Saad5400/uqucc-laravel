<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import GenerateQuizDialog from '@/components/manage/quiz/GenerateQuizDialog.vue';
import QuizCard from '@/components/manage/quiz/QuizCard.vue';
import QuizEditorDialog from '@/components/manage/quiz/QuizEditorDialog.vue';
import type { Limits, QueuedDay, Quiz, Topic } from '@/components/manage/quiz/types';
import { statusBadges } from '@/components/manage/quiz/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { TagsInput, TagsInputInput, TagsInputItem, TagsInputItemDelete, TagsInputItemText } from '@/components/ui/tags-input';
import { Textarea } from '@/components/ui/textarea';
import { arabicCount } from '@/lib/arabic';
import { formatDateTime, formatDayOffset, formatRelativeTime, formatShortDate, formatWeekdayDate } from '@/lib/formatters';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CalendarClock, EllipsisVertical, Library, Loader2, Pencil, PenLine, Plus, Sparkles, Trash2, Trophy } from 'lucide-vue-next';
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
    currentQuiz: Quiz | null;
    upcoming: QueuedDay[];
    pastCount: number;
    limits: Limits;
    today: string;
    nextFreeDate: string;
    todayQuizStatus: Quiz['status'] | null;
    weeklyTop: Player[];
    allTimeTop: Player[];
}>();

/* ------------------------------------------------------------------ */
/* The one question the admin can act on right now                     */
/* ------------------------------------------------------------------ */

/** Today's question exists and has already gone out — it can never change again. */
const todayIsPosted = computed(() => props.todayQuizStatus !== null && props.todayQuizStatus !== 'ready');

/** The front door is showing a future day rather than today. */
const showingFutureDay = computed(() => props.currentQuiz !== null && props.currentQuiz.quiz_date !== props.today);

/**
 * Why the question on screen is the one on screen. Never leave the admin
 * guessing which day they are about to edit.
 */
const currentQuizContext = computed(() => {
    if (props.currentQuiz === null) {
        return null;
    }

    if (!showingFutureDay.value) {
        return { tone: 'muted' as const, text: 'هذا سؤال اليوم — راجعه وعدّله قبل النشر التلقائي الساعة 4 عصراً.' };
    }

    const day = `${formatWeekdayDate(props.currentQuiz.quiz_date)} (${formatDayOffset(props.currentQuiz.quiz_date, props.today)})`;

    if (todayIsPosted.value) {
        return { tone: 'muted' as const, text: `سؤال اليوم نُشر بالفعل ولم يعد قابلاً للتعديل — المعروض هنا هو سؤال ${day}.` };
    }

    return { tone: 'warning' as const, text: `لا يوجد سؤال لليوم! المعروض هو أقرب سؤال قادم — ${day}. ولّد سؤالاً لليوم قبل الساعة 4 عصراً.` };
});

/** The queue after the question already shown in full above it. */
const queueAhead = computed(() => props.upcoming.filter((day) => day.id !== props.currentQuiz?.id));

/* ------------------------------------------------------------------ */
/* Generate / write / edit / delete                                    */
/* ------------------------------------------------------------------ */

const generateDialogOpen = ref(false);

const quizDialogOpen = ref(false);
const editingQuiz = ref<Quiz | null>(null);

function openQuizEditor(quiz: Quiz | null): void {
    editingQuiz.value = quiz;
    quizDialogOpen.value = true;
}

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
    prompt_hint: '',
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

        <!-- The one question that can be edited right now -->
        <Card>
            <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                <CardTitle class="text-lg">{{ showingFutureDay ? 'السؤال القادم' : 'سؤال اليوم' }}</CardTitle>
                <div class="flex flex-wrap items-center gap-2">
                    <Button size="sm" variant="outline" @click="openQuizEditor(null)">
                        <PenLine class="size-4" />
                        كتابة سؤال يدوياً
                    </Button>
                    <Button size="sm" @click="generateDialogOpen = true">
                        <Sparkles class="size-4" />
                        توليد بالذكاء الاصطناعي
                    </Button>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <p
                    v-if="currentQuizContext"
                    class="rounded-lg border p-3 text-sm"
                    :class="
                        currentQuizContext.tone === 'warning'
                            ? 'border-destructive/40 bg-destructive/5 text-destructive-foreground'
                            : 'border-border bg-muted/50 text-muted-foreground'
                    "
                >
                    {{ currentQuizContext.text }}
                </p>

                <EmptyState
                    v-if="currentQuiz === null"
                    :icon="Sparkles"
                    title="لا يوجد سؤال بانتظار النشر"
                    description="كل يوم يحتاج سؤالاً واحداً. ولّده بالذكاء الاصطناعي من أحد المواضيع أدناه، أو اكتبه بنفسك — ثم راجع نصه وخياراته وتلميحاته قبل موعد النشر."
                >
                    <div class="flex flex-wrap justify-center gap-2">
                        <Button size="sm" @click="generateDialogOpen = true">
                            <Sparkles class="size-4" />
                            توليد سؤال
                        </Button>
                        <Button size="sm" variant="outline" @click="openQuizEditor(null)">
                            <PenLine class="size-4" />
                            كتابة سؤال يدوياً
                        </Button>
                    </div>
                </EmptyState>

                <div v-else class="rounded-lg border border-border p-4">
                    <QuizCard :quiz="currentQuiz" prominent @edit="openQuizEditor" @delete="(quiz) => (deletingQuiz = quiz)" />
                </div>
            </CardContent>
        </Card>

        <!-- The rest of the schedule -->
        <Card>
            <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                <CardTitle class="text-lg">الأيام المجهّزة</CardTitle>
                <Button as-child size="sm" variant="outline">
                    <Link href="/manage/quiz/archive">
                        <Library class="size-4" />
                        كل الأسئلة
                        <span v-if="pastCount" class="text-muted-foreground tabular-nums">({{ pastCount }} سابق)</span>
                    </Link>
                </Button>
            </CardHeader>
            <CardContent class="space-y-3">
                <EmptyState
                    v-if="!queueAhead.length"
                    :icon="CalendarClock"
                    title="لا توجد أيام محجوزة بعد"
                    description="يكفي أن يُولَّد سؤال كل فجر، لكن تجهيز أيام قادمة مسبقاً يحميك من تعطّل التوليد — ولّد سؤالاً لتاريخ قادم من زر «توليد بالذكاء الاصطناعي»."
                />

                <template v-else>
                    <ul class="flex flex-wrap gap-2">
                        <li
                            v-for="day in queueAhead"
                            :key="day.id"
                            class="flex items-center gap-2 rounded-full border border-border py-1 ps-2 pe-3 text-xs"
                        >
                            <span class="tabular-nums">{{ formatShortDate(day.quiz_date) }}</span>
                            <span class="text-muted-foreground">{{ formatDayOffset(day.quiz_date, today) }}</span>
                            <Badge v-if="day.status !== 'ready'" :variant="statusBadges[day.status].variant">
                                {{ statusBadges[day.status].label }}
                            </Badge>
                            <span v-else-if="day.topic" class="text-muted-foreground">{{ day.topic }}</span>
                        </li>
                    </ul>
                    <p class="text-xs text-muted-foreground">
                        {{ arabicCount(queueAhead.length, { singular: 'يوم آخر مجهّز', dual: 'يومان آخران مجهّزان', plural: 'أيام أخرى مجهّزة' }) }}
                        إلى جانب السؤال المعروض أعلاه. أول يوم فارغ:
                        <span class="tabular-nums">{{ formatWeekdayDate(nextFreeDate) }}</span
                        >.
                    </p>
                </template>
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

    <GenerateQuizDialog v-model:open="generateDialogOpen" :topics="topics" :upcoming="upcoming" :today="today" :default-date="nextFreeDate" />

    <QuizEditorDialog
        v-model:open="quizDialogOpen"
        :quiz="editingQuiz"
        :topics="topics"
        :limits="limits"
        :today="today"
        :default-date="nextFreeDate"
    />

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
