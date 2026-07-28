<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import Pagination from '@/components/manage/Pagination.vue';
import QuizCard from '@/components/manage/quiz/QuizCard.vue';
import QuizEditorDialog from '@/components/manage/quiz/QuizEditorDialog.vue';
import type { Limits, Quiz, Topic } from '@/components/manage/quiz/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatNumber } from '@/lib/formatters';
import type { Paginated } from '@/lib/pagination';
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarClock, ChevronRight, History } from 'lucide-vue-next';
import { ref } from 'vue';

defineOptions({ layout: ManageLayout });

type Scope = 'upcoming' | 'past';

const props = defineProps<{
    scope: Scope;
    quizzes: Paginated<Quiz>;
    counts: { upcoming: number; past: number };
    topics: Topic[];
    limits: Limits;
    today: string;
}>();

/**
 * The two halves answer different questions and want opposite orderings —
 * the queue reads forward from today, history reads backward from the most
 * recent — so each is its own URL-addressable scope rather than one list
 * nobody can scan.
 */
const scopes: { key: Scope; label: string; icon: typeof CalendarClock }[] = [
    { key: 'upcoming', label: 'القادمة', icon: CalendarClock },
    { key: 'past', label: 'السابقة', icon: History },
];

function visit(scope: Scope, page: number): void {
    router.get(
        '/manage/quiz/archive',
        { scope, page },
        {
            preserveState: true,
            preserveScroll: scope === props.scope,
            replace: true,
        },
    );
}

const quizDialogOpen = ref(false);
const editingQuiz = ref<Quiz | null>(null);

function openQuizEditor(quiz: Quiz): void {
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
</script>

<template>
    <Head title="كل أسئلة سؤال اليوم" />
    <PageHeader title="كل الأسئلة" description="أرشيف أسئلة «سؤال اليوم» — الأيام القادمة المجهّزة والأسئلة التي نُشرت سابقاً">
        <template #actions>
            <Button as-child variant="outline" size="sm">
                <Link href="/manage/quiz">
                    <ChevronRight class="size-4" />
                    العودة إلى سؤال اليوم
                </Link>
            </Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <div class="flex flex-wrap gap-2">
            <Button
                v-for="option in scopes"
                :key="option.key"
                size="sm"
                :variant="option.key === scope ? 'default' : 'outline'"
                @click="visit(option.key, 1)"
            >
                <component :is="option.icon" class="size-4" />
                {{ option.label }}
                <span class="tabular-nums">({{ formatNumber(counts[option.key]) }})</span>
            </Button>
        </div>

        <Card>
            <CardContent class="space-y-4 pt-6">
                <EmptyState
                    v-if="!quizzes.data.length"
                    :icon="scope === 'upcoming' ? CalendarClock : History"
                    :title="scope === 'upcoming' ? 'لا توجد أسئلة قادمة' : 'لا توجد أسئلة سابقة'"
                    :description="
                        scope === 'upcoming'
                            ? 'لا يوم مجهّز بعد اليوم. جهّز أياماً قادمة من صفحة «سؤال اليوم» كي لا يوقفك تعطّل التوليد الليلي.'
                            : 'لم يُنشر أي سؤال بعد. ستظهر هنا الأسئلة السابقة بنتائجها بمجرد أن يبدأ النشر اليومي.'
                    "
                />

                <ul v-else class="overflow-hidden rounded-lg border border-border">
                    <li v-for="quiz in quizzes.data" :key="quiz.id" class="border-b border-border p-3 last:border-b-0">
                        <QuizCard :quiz="quiz" @edit="openQuizEditor" @delete="(target) => (deletingQuiz = target)" />
                    </li>
                </ul>

                <Pagination
                    :page="quizzes.current_page"
                    :pages="quizzes.last_page"
                    :total="quizzes.total"
                    @update:page="(page) => visit(scope, page)"
                />
            </CardContent>
        </Card>
    </div>

    <QuizEditorDialog v-model:open="quizDialogOpen" :quiz="editingQuiz" :topics="topics" :limits="limits" :today="today" :default-date="today" />

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
</template>
