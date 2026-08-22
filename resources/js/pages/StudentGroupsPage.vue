<script setup lang="ts">
import GroupAnswer from '@/components/groups/GroupAnswer.vue';
import JoinChecklist from '@/components/groups/JoinChecklist.vue';
import type { Cohort, StudentGroup } from '@/components/groups/types';
import DocsLayout from '@/components/layout/DocsLayout.vue';
import PageHeader from '@/components/page/PageHeader.vue';
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { arabicDigits } from '@/lib/arabic';
import { MessagesSquare } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

defineOptions({ layout: false });

interface Props {
    cohorts: Cohort[];
    page?: { html_content: unknown; title?: string };
    hasContent?: boolean;
    seo: SeoData;
}

const props = withDefaults(defineProps<Props>(), { hasContent: false });

const SECTION_KEY = 'uqucc-section';

/* ------------------------------------------------------------------ */
/* What the student tells us about themselves                          */
/* ------------------------------------------------------------------ */

const cohortId = ref<number | null>(props.cohorts[0]?.id ?? null);
const section = ref<string | null>(null);
const branch = ref<string | null>(null);
const major = ref<string | null>(null);

const activeCohort = computed<Cohort | null>(() => props.cohorts.find((cohort) => cohort.id === cohortId.value) ?? null);

interface Option {
    value: string;
    label: string;
}

/** Distinct values in the order the panel put the groups in, so admins control it. */
function distinctBy(groups: StudentGroup[], value: (group: StudentGroup) => string | null, label: (group: StudentGroup) => string): Option[] {
    const seen = new Map<string, Option>();

    for (const group of groups) {
        const key = value(group);

        if (key !== null && !seen.has(key)) {
            seen.set(key, { value: key, label: label(group) });
        }
    }

    return [...seen.values()];
}

const programmeGroups = computed(() => (activeCohort.value?.groups ?? []).filter((group) => !group.is_general));

/** The one list published to the whole batch, whatever anyone is studying. */
const globalGroup = computed(() => (activeCohort.value?.groups ?? []).find((group) => group.is_general) ?? null);

const branchOptions = computed(() =>
    distinctBy(
        programmeGroups.value,
        (group) => group.branch,
        (group) => group.branch_label,
    ),
);

const majorOptions = computed(() =>
    distinctBy(
        programmeGroups.value,
        (group) => group.major,
        (group) => group.name,
    ),
);

/**
 * Keep the answers valid as the student moves between batches: each has its own
 * branches and programmes, and a stale choice would silently resolve to nothing.
 */
watch(
    activeCohort,
    () => {
        if (!majorOptions.value.some((option) => option.value === major.value)) {
            major.value = null;
        }

        if (!branchOptions.value.some((option) => option.value === branch.value)) {
            branch.value = branchOptions.value[0]?.value ?? null;
        }
    },
    { immediate: true },
);

/* ------------------------------------------------------------------ */
/* The two groups they join                                            */
/* ------------------------------------------------------------------ */

const programmeGroup = computed<StudentGroup | null>(() => {
    if (major.value === null) {
        return null;
    }

    return programmeGroups.value.find((group) => group.major === major.value && group.branch === branch.value) ?? null;
});

const majorLabel = computed(() => majorOptions.value.find((option) => option.value === major.value)?.label ?? '');

const branchLabel = computed(() => branchOptions.value.find((option) => option.value === branch.value)?.label ?? '');

/** The programme exists in this batch, just not at the branch they picked. */
const branchesOfferingMajor = computed(() =>
    major.value === null
        ? []
        : programmeGroups.value.filter((group) => group.major === major.value).map((group) => ({ value: group.branch, label: group.branch_label })),
);

/* ------------------------------------------------------------------ */
/* Remembered + shareable                                              */
/* ------------------------------------------------------------------ */

/**
 * Restore what we can without asking twice: the section is the one answer that
 * never changes for a person, and a shared link carries the rest. Both are read
 * after mount — this page is prerendered on the server, where neither
 * `localStorage` nor the query string exists.
 */
onMounted(() => {
    const stored = localStorage.getItem(SECTION_KEY);

    if (stored === 'men' || stored === 'women') {
        section.value = stored;
    }

    const params = new URLSearchParams(window.location.search);
    const linkedCohort = Number(params.get('cohort'));

    if (props.cohorts.some((cohort) => cohort.id === linkedCohort)) {
        cohortId.value = linkedCohort;
    }

    const linkedSection = params.get('section');

    if (linkedSection === 'men' || linkedSection === 'women') {
        section.value = linkedSection;
    }

    const linkedBranch = params.get('branch');

    if (branchOptions.value.some((option) => option.value === linkedBranch)) {
        branch.value = linkedBranch;
    }

    const linkedMajor = params.get('major');

    if (majorOptions.value.some((option) => option.value === linkedMajor)) {
        major.value = linkedMajor;
    }
});

watch(section, (value) => {
    if (value !== null) {
        localStorage.setItem(SECTION_KEY, value);
    }
});

/** Keep the address bar shareable without asking the server for anything. */
watch([cohortId, section, branch, major], ([cohort, chosenSection, chosenBranch, chosenMajor]) => {
    const params = new URLSearchParams();

    if (cohort !== null) params.set('cohort', String(cohort));
    if (chosenSection !== null) params.set('section', chosenSection);
    if (chosenMajor !== null) params.set('major', chosenMajor);
    if (chosenMajor !== null && chosenBranch !== null) params.set('branch', chosenBranch);

    const query = params.toString();
    window.history.replaceState(null, '', query === '' ? window.location.pathname : `?${query}`);
});

const sectionOptions: Option[] = [
    { value: 'men', label: 'شطر الطلاب' },
    { value: 'women', label: 'شطر الطالبات' },
];
</script>

<template>
    <SeoHead :seo="seo" />
    <DocsLayout>
        <PageHeader title="قروبات الدفعات" icon="solar:users-group-two-rounded-broken" />

        <div v-if="hasContent" class="typography mb-6">
            <RichContentRenderer :content="page?.html_content" />
        </div>

        <div
            v-if="!cohorts.length"
            class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-border px-6 py-16 text-center"
        >
            <div class="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <MessagesSquare class="size-6" />
            </div>
            <div class="space-y-1">
                <p class="font-medium">لا توجد قروبات معلنة حالياً</p>
                <p class="text-sm text-muted-foreground">ستظهر هنا قروبات الدفعات ومشرفوها بمجرد إضافتها. تابع الصفحة مع بداية الفصل الدراسي.</p>
            </div>
        </div>

        <div v-else class="mx-auto max-w-4xl space-y-10">
            <!-- 1 · who the student is: everything below is an answer to this -->
            <section class="space-y-4">
                <div class="flex items-center gap-3">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground">
                        {{ arabicDigits(1) }}
                    </span>
                    <h2 class="m-0 text-lg font-bold">عرّفنا بنفسك</h2>
                </div>

                <div class="space-y-4 rounded-2xl border border-border bg-card p-4 shadow-sm sm:p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="cohort-select">الدفعة</Label>
                            <Select :model-value="cohortId === null ? undefined : String(cohortId)" @update:model-value="cohortId = Number($event)">
                                <SelectTrigger id="cohort-select" class="w-full">
                                    <SelectValue placeholder="اختر دفعتك" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="cohort in cohorts" :key="cohort.id" :value="String(cohort.id)">
                                        {{ cohort.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <Label for="section-select">الشطر</Label>
                            <Select :model-value="section ?? undefined" @update:model-value="section = String($event)">
                                <SelectTrigger id="section-select" class="w-full">
                                    <SelectValue placeholder="اختر شطرك" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="option in sectionOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <Label for="major-select">التخصص</Label>
                            <Select :model-value="major ?? undefined" @update:model-value="major = String($event)">
                                <SelectTrigger id="major-select" class="w-full">
                                    <SelectValue placeholder="اختر تخصصك" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="option in majorOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <Label for="branch-select">الفرع</Label>
                            <Select :model-value="branch ?? undefined" @update:model-value="branch = String($event)">
                                <SelectTrigger id="branch-select" class="w-full">
                                    <SelectValue placeholder="اختر فرعك" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="option in branchOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 2 · what to have ready. Above the contact buttons on purpose: tapping one
                 leaves the site for WhatsApp or Telegram, so anything under it is never read. -->
            <section v-if="activeCohort?.requirements.length" class="space-y-4">
                <div class="flex items-center gap-3">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground">
                        {{ arabicDigits(2) }}
                    </span>
                    <div>
                        <h2 class="m-0 text-lg font-bold">جهّز هذي قبل ما تراسل</h2>
                        <p class="m-0 text-sm text-muted-foreground">أرسلها لكل مشرف من المشرفَين.</p>
                    </div>
                </div>

                <div class="rounded-2xl border border-border bg-card p-4 shadow-sm sm:p-5">
                    <JoinChecklist :requirements="activeCohort.requirements" />
                </div>
            </section>

            <!-- 3 · both groups, side by side: joining one is not joining -->
            <section class="space-y-4">
                <div class="flex items-center gap-3">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground">
                        {{ arabicDigits(3) }}
                    </span>
                    <div>
                        <h2 class="m-0 text-lg font-bold">راسل مشرفي القروبين</h2>
                        <p class="m-0 text-sm text-muted-foreground">تنضم لقروبين معاً: القروب العام لدفعتك، وقروب تخصصك. راسل مشرف كل واحد منهما.</p>
                    </div>
                </div>

                <p v-if="activeCohort?.note" class="rounded-2xl border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
                    {{ activeCohort.note }}
                </p>

                <div v-if="section === null" class="rounded-2xl border border-dashed border-border px-6 py-12 text-center">
                    <p class="font-medium">اختر شطرك أولاً</p>
                    <p class="mt-1 text-sm text-muted-foreground">نعرض لك مشرفاً من شطرك، لأن الانضمام يتم عن طريق مشرفي الشطر نفسه.</p>
                </div>

                <!-- items-start so an empty slot (a batch with no global group) does not stretch to match a filled one -->
                <div v-else class="grid items-start gap-4 md:grid-cols-2">
                    <GroupAnswer title="القروب العام" :subtitle="activeCohort?.name" :group="globalGroup" :section-key="section">
                        <template #empty>
                            <span v-if="!globalGroup">لا يوجد قروب عام لهذه الدفعة — اكتفِ بقروب تخصصك.</span>
                            <span v-else>لا يوجد مشرف متاح في القروب العام حالياً. جرّب زيارة الصفحة لاحقاً.</span>
                        </template>
                    </GroupAnswer>

                    <GroupAnswer
                        :title="major === null ? 'قروب تخصصك' : majorLabel"
                        :subtitle="major === null ? undefined : branchLabel"
                        :group="programmeGroup"
                        :section-key="section"
                    >
                        <template #empty>
                            <span v-if="major === null">اختر تخصصك أعلاه ليظهر لك مشرف قروب تخصصك.</span>
                            <span v-else-if="!programmeGroup && branchesOfferingMajor.length" class="block space-y-3">
                                <span class="block">«{{ majorLabel }}» ليس له قروب في هذا الفرع. متاح في:</span>
                                <span class="flex flex-wrap justify-center gap-2">
                                    <Button
                                        v-for="option in branchesOfferingMajor"
                                        :key="option.value ?? ''"
                                        variant="outline"
                                        size="sm"
                                        @click="branch = option.value"
                                    >
                                        {{ option.label }}
                                    </Button>
                                </span>
                            </span>
                            <span v-else-if="!programmeGroup">«{{ majorLabel }}» ليس له قروب في هذه الدفعة.</span>
                            <span v-else>لا يوجد مشرف من شطرك في هذا القروب حالياً.</span>
                        </template>
                    </GroupAnswer>
                </div>
            </section>
        </div>
    </DocsLayout>
</template>
