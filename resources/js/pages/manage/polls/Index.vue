<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import PollEditorDialog from '@/components/manage/polls/PollEditorDialog.vue';
import PollResults from '@/components/manage/polls/PollResults.vue';
import type { Limits, Poll, QueuedPoll, Suggestion } from '@/components/manage/polls/types';
import { statusBadges } from '@/components/manage/polls/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { TagsInput, TagsInputInput, TagsInputItem, TagsInputItemDelete, TagsInputItemText } from '@/components/ui/tags-input';
import { arabicCount } from '@/lib/arabic';
import { formatDateTime, formatDayOffset, formatShortDate, formatTimeOfDay, formatWeekdayDate } from '@/lib/formatters';
import { Head, router, useForm } from '@inertiajs/vue3';
import { BarChart3, CalendarClock, Loader2, MessageSquareDashed, Pencil, PenLine, Send, SquareCheckBig, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

interface PollSettingsValues {
    enabled: boolean;
    chat_ids: string[];
    post_time: string;
    open_hours: number;
}

interface GroupChat {
    chat_id: string;
    title: string | null;
}

const props = defineProps<{
    settings: PollSettingsValues;
    groupChats: GroupChat[];
    schedule: { post_time: string; today_posts_at: string };
    livePoll: Poll | null;
    currentPoll: Poll | null;
    upcoming: QueuedPoll[];
    recent: Poll[];
    suggestions: Suggestion[];
    limits: Limits;
    today: string;
    nextFreeDate: string;
}>();

const configured = computed(() => props.settings.enabled && props.settings.chat_ids.length > 0);

/** The queue after the two polls already shown in full above it. */
const queueAhead = computed(() => props.upcoming.filter((poll) => poll.id !== props.currentPoll?.id && poll.id !== props.livePoll?.id));

/** Why the next poll is the one on screen — never leave the admin guessing which day they are editing. */
const nextPollContext = computed(() => {
    if (props.currentPoll === null) {
        return null;
    }

    if (props.currentPoll.poll_date === props.today) {
        return `يُنشر اليوم ${formatTimeOfDay(props.currentPoll.post_time ?? props.schedule.post_time)}.`;
    }

    return `${formatWeekdayDate(props.currentPoll.poll_date)} (${formatDayOffset(props.currentPoll.poll_date, props.today)}) — ${formatTimeOfDay(props.currentPoll.post_time ?? props.schedule.post_time)}.`;
});

/* ------------------------------------------------------------------ */
/* Writing, editing and deleting                                       */
/* ------------------------------------------------------------------ */

const editorOpen = ref(false);
const editingPoll = ref<Poll | null>(null);

function openEditor(poll: Poll | null): void {
    editingPoll.value = poll;
    editorOpen.value = true;
}

const deletingPoll = ref<Poll | null>(null);
const deleteProcessing = ref(false);

function deletePoll(): void {
    if (!deletingPoll.value) {
        return;
    }

    deleteProcessing.value = true;

    router.delete(`/manage/polls/${deletingPoll.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deletingPoll.value = null;
        },
        onFinish: () => {
            deleteProcessing.value = false;
        },
    });
}

/* ------------------------------------------------------------------ */
/* Posting and closing by hand                                         */
/* ------------------------------------------------------------------ */

const postingPoll = ref<Poll | null>(null);
const postProcessing = ref(false);

function postNow(): void {
    if (!postingPoll.value) {
        return;
    }

    postProcessing.value = true;

    router.post(
        `/manage/polls/${postingPoll.value.id}/post`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                postingPoll.value = null;
            },
            onFinish: () => {
                postProcessing.value = false;
            },
        },
    );
}

const closingPoll = ref<Poll | null>(null);
const closeProcessing = ref(false);

function closeNow(): void {
    if (!closingPoll.value) {
        return;
    }

    closeProcessing.value = true;

    router.post(
        `/manage/polls/${closingPoll.value.id}/close`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                closingPoll.value = null;
            },
            onFinish: () => {
                closeProcessing.value = false;
            },
        },
    );
}

/** Why «نشر الآن» is unavailable for the next poll, or null when it is available. */
const postNowBlockedReason = computed(() => {
    if (!configured.value) {
        return 'فعّل استطلاع الرأي وحدد المجموعات المستهدفة أولاً.';
    }

    if (props.currentPoll === null) {
        return 'لا يوجد استطلاع في الطابور — اكتب واحداً أولاً.';
    }

    return null;
});

/* ------------------------------------------------------------------ */
/* Settings                                                            */
/* ------------------------------------------------------------------ */

const settingsForm = useForm({
    enabled: props.settings.enabled,
    chat_ids: [...props.settings.chat_ids],
    post_time: props.settings.post_time,
    open_hours: props.settings.open_hours,
});

function submitSettings(): void {
    settingsForm
        .transform((data) => ({ ...data, open_hours: Number(data.open_hours) }))
        .put('/manage/polls/settings', {
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
</script>

<template>
    <Head title="استطلاع الرأي" />
    <PageHeader
        title="استطلاع الرأي"
        description="سؤال رأي مجهول بلا إجابة صحيحة، يُنشر يومياً في مجموعة التليجرام وتُعلن نتيجته بعد يوم — أخف باب يدخل منه العضو الصامت"
    />

    <div class="space-y-6">
        <div v-if="!configured" class="rounded-lg border border-border bg-muted/50 p-4 text-sm">
            استطلاع الرأي غير مفعّل بعد — فعّله وحدد المجموعة المستهدفة من بطاقة «الإعدادات» أسفل الصفحة ليبدأ النشر التلقائي.
        </div>

        <p v-if="$page.props.errors.post" class="text-sm text-destructive-foreground">{{ $page.props.errors.post }}</p>
        <p v-if="$page.props.errors.poll" class="text-sm text-destructive-foreground">{{ $page.props.errors.poll }}</p>

        <!-- The poll the group is voting in right now -->
        <Card v-if="livePoll">
            <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                <div class="space-y-1">
                    <CardTitle class="text-lg">مفتوح الآن</CardTitle>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="livePoll.closes_at">تُعلن النتيجة {{ formatDateTime(livePoll.closes_at) }}</template>
                        <template v-else>يُغلق مع نشر الاستطلاع التالي</template>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button variant="ghost" size="sm" @click="postingPoll = livePoll">
                        <Send class="size-4" />
                        إعادة النشر
                    </Button>
                    <Button variant="outline" size="sm" @click="closingPoll = livePoll">
                        <SquareCheckBig class="size-4" />
                        إغلاق ونشر النتيجة
                    </Button>
                </div>
            </CardHeader>
            <CardContent class="space-y-3">
                <p class="font-medium">{{ livePoll.question }}</p>
                <ul class="flex flex-wrap gap-2">
                    <li v-for="option in livePoll.options" :key="option" class="rounded-full border border-border px-3 py-1 text-sm">
                        {{ option }}
                    </li>
                </ul>
                <p class="text-xs text-muted-foreground">
                    الأصوات مجهولة، فلا تصل عدداً إلا عند الإغلاق — عندها تُجمع أصوات كل المجموعات وتُنشر النتيجة رداً على الاستطلاع نفسه.
                </p>
            </CardContent>
        </Card>

        <!-- The next poll in the queue -->
        <Card>
            <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                <div class="space-y-1">
                    <CardTitle class="text-lg">الاستطلاع القادم</CardTitle>
                    <p v-if="nextPollContext" class="text-xs text-muted-foreground">{{ nextPollContext }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="currentPoll"
                        variant="outline"
                        size="sm"
                        :disabled="postNowBlockedReason !== null"
                        :title="postNowBlockedReason ?? undefined"
                        @click="postingPoll = currentPoll"
                    >
                        <Send class="size-4" />
                        نشر الآن
                    </Button>
                    <Button size="sm" @click="openEditor(null)">
                        <PenLine class="size-4" />
                        استطلاع جديد
                    </Button>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <EmptyState
                    v-if="currentPoll === null"
                    :icon="MessageSquareDashed"
                    title="لا يوجد استطلاع في الطابور"
                    description="استطلاع الرأي يُكتب باليد — لا يولّده الذكاء الاصطناعي — والطابور الفارغ يعني يوماً صامتاً. اكتب واحداً أو ابدأ من الاقتراحات الجاهزة داخل النافذة."
                >
                    <Button size="sm" @click="openEditor(null)">
                        <PenLine class="size-4" />
                        استطلاع جديد
                    </Button>
                </EmptyState>

                <div v-else class="space-y-3 rounded-lg border border-border p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <p class="font-medium">{{ currentPoll.question }}</p>
                        <div class="flex items-center gap-1">
                            <Button variant="ghost" size="icon" aria-label="تعديل الاستطلاع" @click="openEditor(currentPoll)">
                                <Pencil />
                            </Button>
                            <Button variant="ghost" size="icon" aria-label="حذف الاستطلاع" @click="deletingPoll = currentPoll">
                                <Trash2 />
                            </Button>
                        </div>
                    </div>
                    <ul class="flex flex-wrap gap-2">
                        <li v-for="option in currentPoll.options" :key="option" class="rounded-full border border-border px-3 py-1 text-sm">
                            {{ option }}
                        </li>
                    </ul>
                </div>
            </CardContent>
        </Card>

        <!-- The rest of the queue -->
        <Card>
            <CardHeader>
                <CardTitle class="text-lg">الأيام المجهّزة</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <EmptyState
                    v-if="!queueAhead.length"
                    :icon="CalendarClock"
                    title="لا توجد أيام محجوزة بعد"
                    description="جهّز أسبوعاً دفعة واحدة: الاستطلاع أسرع ما يُكتب في هذه اللوحة، وطابور ممتلئ يعني أن المجموعة لا تفقد الطقس اليومي في أول يوم انشغال."
                />

                <template v-else>
                    <ul class="space-y-1">
                        <li
                            v-for="poll in queueAhead"
                            :key="poll.id"
                            class="flex items-center gap-3 rounded-md border border-border px-3 py-2 text-sm"
                        >
                            <span class="shrink-0 tabular-nums">{{ formatShortDate(poll.poll_date) }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ formatDayOffset(poll.poll_date, today) }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ poll.question }}</span>
                            <Badge v-if="poll.status !== 'ready'" :variant="statusBadges[poll.status].variant">
                                {{ statusBadges[poll.status].label }}
                            </Badge>
                        </li>
                    </ul>
                    <p class="text-xs text-muted-foreground">
                        {{ arabicCount(queueAhead.length, { singular: 'يوم آخر مجهّز', dual: 'يومان آخران مجهّزان', plural: 'أيام أخرى مجهّزة' }) }}.
                        أول يوم فارغ: <span class="tabular-nums">{{ formatWeekdayDate(nextFreeDate) }}</span
                        >.
                    </p>
                </template>
            </CardContent>
        </Card>

        <!-- What the group answered -->
        <Card>
            <CardHeader>
                <CardTitle class="text-lg">النتائج السابقة</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <EmptyState
                    v-if="!recent.length"
                    :icon="BarChart3"
                    title="لا نتائج بعد"
                    description="تُحفظ نتيجة كل استطلاع هنا لحظة إغلاقه — وهي المكان الوحيد الذي تبقى فيه، فالتصويت مجهول ولا يُنسب لأحد."
                />

                <div v-else class="grid gap-4 lg:grid-cols-2">
                    <div v-for="poll in recent" :key="poll.id" class="space-y-3 rounded-lg border border-border p-4">
                        <div class="space-y-1">
                            <p class="font-medium">{{ poll.question }}</p>
                            <p class="text-xs text-muted-foreground">
                                <span class="tabular-nums">{{ formatShortDate(poll.poll_date) }}</span> —
                                {{ arabicCount(poll.total_votes, { singular: 'صوت', dual: 'صوتان', plural: 'أصوات', feminineOne: 'واحد' }) }}
                            </p>
                        </div>
                        <PollResults :poll="poll" />
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Settings -->
        <Card class="max-w-2xl">
            <CardHeader>
                <CardTitle class="text-lg">الإعدادات</CardTitle>
            </CardHeader>
            <CardContent>
                <form class="space-y-6" @submit.prevent="submitSettings">
                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <Label for="polls-enabled">تفعيل استطلاع الرأي</Label>
                            <p class="text-xs text-muted-foreground">
                                عند التفعيل: يُنشر استطلاع اليوم — إن وُجد في الطابور — في موعده، ويُغلق بعد المدة المحددة وتُعلن نتيجته. الأيام
                                الفارغة تمرّ بهدوء بلا رسائل.
                            </p>
                        </div>
                        <Switch id="polls-enabled" v-model="settingsForm.enabled" />
                    </div>
                    <p v-if="settingsForm.errors.enabled" class="text-sm text-destructive-foreground">{{ settingsForm.errors.enabled }}</p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="polls-post-time">موعد النشر</Label>
                            <Input
                                id="polls-post-time"
                                v-model="settingsForm.post_time"
                                type="time"
                                dir="ltr"
                                class="w-40 text-start tabular-nums"
                                :aria-invalid="settingsForm.errors.post_time ? true : undefined"
                            />
                            <p v-if="settingsForm.errors.post_time" class="text-sm text-destructive-foreground">
                                {{ settingsForm.errors.post_time }}
                            </p>
                            <p v-else class="text-xs text-muted-foreground">
                                بتوقيت مكة المكرمة. اجعله بعيداً عن موعد سؤال اليوم كي لا يتنافس الطقسان على الانتباه نفسه.
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="polls-open-hours">مدة التصويت (ساعات)</Label>
                            <Input
                                id="polls-open-hours"
                                v-model="settingsForm.open_hours"
                                type="number"
                                min="1"
                                :max="limits.max_open_hours"
                                dir="ltr"
                                class="w-40 text-start tabular-nums"
                                :aria-invalid="settingsForm.errors.open_hours ? true : undefined"
                            />
                            <p v-if="settingsForm.errors.open_hours" class="text-sm text-destructive-foreground">
                                {{ settingsForm.errors.open_hours }}
                            </p>
                            <p v-else class="text-xs text-muted-foreground">
                                يوم كامل يعطي من يفتح المجموعة مرة واحدة يومياً فرصة التصويت قبل إعلان النتيجة.
                            </p>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <Label for="polls-chat-ids">المجموعات المستهدفة</Label>
                        <TagsInput id="polls-chat-ids" v-model="settingsForm.chat_ids" :aria-invalid="chatIdsError ? true : undefined">
                            <TagsInputItem v-for="chatId in settingsForm.chat_ids" :key="chatId" :value="chatId" dir="ltr">
                                <TagsInputItemText />
                                <TagsInputItemDelete class="-m-1.5 p-1.5" :aria-label="`إزالة ${chatId}`" />
                            </TagsInputItem>
                            <TagsInputInput placeholder="أضف معرّف مجموعة…" dir="auto" class="text-start" />
                        </TagsInput>
                        <p class="text-xs text-muted-foreground">
                            يُنشر الاستطلاع نفسه في كل مجموعة وتُجمع أصواتها في نتيجة واحدة. معرّفات المجموعات تبدأ بإشارة سالبة. لمجموعة تستخدم
                            المواضيع (Topics) أضف معرّف الموضوع بعد نقطتين، مثل <span dir="ltr" class="font-mono tabular-nums">-100…:42</span>.
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

    <PollEditorDialog
        v-model:open="editorOpen"
        :poll="editingPoll"
        :limits="limits"
        :suggestions="suggestions"
        :today="today"
        :default-date="nextFreeDate"
    />

    <ConfirmDialog
        :open="postingPoll !== null"
        :title="postingPoll?.status === 'posted' ? 'إعادة نشر الاستطلاع' : 'نشر الاستطلاع الآن'"
        :confirm-label="postingPoll?.status === 'posted' ? 'إعادة النشر' : 'نشر الآن'"
        :processing="postProcessing"
        @confirm="postNow"
        @update:open="
            (value) => {
                if (!value) postingPoll = null;
            }
        "
    >
        <template v-if="postingPoll?.status === 'posted'">
            سيُرسل الاستطلاع نفسه من جديد إلى المجموعات، وتُوقف الرسالة القديمة. الأصوات المسجّلة قبل ذلك تبقى محسوبة في النتيجة النهائية.
        </template>
        <template v-else> سيُنشر الاستطلاع في المجموعات فوراً بدل انتظار موعده، وإن كان هناك استطلاع مفتوح فسيُغلق الآن وتُعلن نتيجته. </template>
    </ConfirmDialog>

    <ConfirmDialog
        :open="closingPoll !== null"
        title="إغلاق الاستطلاع"
        confirm-label="إغلاق ونشر النتيجة"
        :processing="closeProcessing"
        @confirm="closeNow"
        @update:open="
            (value) => {
                if (!value) closingPoll = null;
            }
        "
    >
        سيتوقف التصويت في كل المجموعات، وتُجمع الأصوات وتُنشر النتيجة رداً على رسالة الاستطلاع. لا يمكن إعادة فتحه بعدها.
    </ConfirmDialog>

    <ConfirmDialog
        :open="deletingPoll !== null"
        title="حذف الاستطلاع"
        destructive
        confirm-label="حذف"
        :processing="deleteProcessing"
        @confirm="deletePoll"
        @update:open="
            (value) => {
                if (!value) deletingPoll = null;
            }
        "
    >
        سيُحذف هذا الاستطلاع من الطابور نهائياً. يمكنك بعدها كتابة استطلاع آخر لليوم نفسه.
    </ConfirmDialog>
</template>
