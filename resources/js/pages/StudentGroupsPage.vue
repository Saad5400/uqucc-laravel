<script setup lang="ts">
import JoinChecklist from '@/components/groups/JoinChecklist.vue';
import JoinStep from '@/components/groups/JoinStep.vue';
import { sectionFor, type Cohort, type GroupSection, type StudentGroup } from '@/components/groups/types';
import DocsLayout from '@/components/layout/DocsLayout.vue';
import PageHeader from '@/components/page/PageHeader.vue';
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { arabicDigits } from '@/lib/arabic';
import type { JoinRequest, StudentDetails } from '@/lib/joinMessage';
import { MessagesSquare } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

defineOptions({ layout: false });

interface Props {
    cohorts: Cohort[];
    page?: { html_content: unknown; title?: string };
    hasContent?: boolean;
    seo: SeoData;
}

const props = withDefaults(defineProps<Props>(), { hasContent: false });

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
    /** Compact form, for the folded summary and card subtitles. */
    short?: string;
}

/** Distinct values in the order the panel put the groups in, so admins control it. */
function distinctBy(
    groups: StudentGroup[],
    value: (group: StudentGroup) => string | null,
    label: (group: StudentGroup) => string,
    short?: (group: StudentGroup) => string,
): Option[] {
    const seen = new Map<string, Option>();

    for (const group of groups) {
        const key = value(group);

        if (key !== null && !seen.has(key)) {
            seen.set(key, { value: key, label: label(group), short: short?.(group) });
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
        (group) => group.branch_short,
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

const cohortLabel = computed(() => activeCohort.value?.name ?? '');

const majorLabel = computed(() => majorOptions.value.find((option) => option.value === major.value)?.label ?? '');

const branchOption = computed(() => branchOptions.value.find((option) => option.value === branch.value));

/** The compact «الرئيسي» rather than «الفرع الرئيسي — مكة المكرمة»: the folded
 *  summary and the card subtitle both have one line to work with on a phone. */
const branchShortLabel = computed(() => branchOption.value?.short ?? branchOption.value?.label ?? '');

/**
 * Both cards are labelled with the batch and nothing else. The branch is a
 * field the student set two rows above; repeating it on the card is noise.
 */
const programmeSubtitle = computed(() => cohortLabel.value);

/** Nothing below step 1 is shown until the student has said both of these. */
const hasAnswered = computed(() => section.value !== null && major.value !== null);

/** The programme exists in this batch, just not at the branch they picked. */
const branchesOfferingMajor = computed(() =>
    major.value === null
        ? []
        : programmeGroups.value.filter((group) => group.major === major.value).map((group) => ({ value: group.branch, label: group.branch_label })),
);

/* ------------------------------------------------------------------ */
/* Joining: two groups, one after the other                            */
/* ------------------------------------------------------------------ */

/**
 * The two groups used to sit side by side as twin cards, and students read that
 * as a choice — «why are there two, which one is mine?». They are not a choice:
 * everyone joins both, and the general group comes first. So they are an ordered
 * sequence with exactly one step open at a time, opening on the general group.
 */
const GENERAL_STEP = 'general';
const PROGRAMME_STEP = 'programme';

/** Only the supervisors of the student's own section can answer them. */
function sectionOf(group: StudentGroup | null): GroupSection | null {
    return group && section.value ? sectionFor(group, section.value) : null;
}

const generalSection = computed(() => sectionOf(globalGroup.value));
const programmeSection = computed(() => sectionOf(programmeGroup.value));

/**
 * The step that opens itself: the general group, unless this batch has none —
 * an unavailable group has nothing to fold away and explains itself in place,
 * so it must not sit there holding the open slot.
 */
const firstStep = computed(() => (generalSection.value ? GENERAL_STEP : PROGRAMME_STEP));

/** `null` follows the sequence; a name is the step the student opened; `''` is all folded away. */
const openedStep = ref<string | null>(null);

const openStep = computed(() => (openedStep.value === null ? firstStep.value : openedStep.value || null));

function toggleStep(step: string): void {
    openedStep.value = openStep.value === step ? '' : step;
}

/* ------------------------------------------------------------------ */
/* Folding step 1                                                      */
/* ------------------------------------------------------------------ */

const selectorOpen = ref(true);

/**
 * Fold step 1 away as soon as the answer is complete.
 *
 * Nothing is remembered between visits, so every student fills this in every
 * time — which means the form would otherwise sit open above the answer for the
 * whole session, and on a phone that is most of a screen. Folding on the change
 * that completes the answer hands the screen back at exactly the moment there
 * is something to show. «تغيير» reopens it.
 */
watch([cohortId, section, branch, major], () => {
    openedStep.value = null;

    if (section.value !== null && major.value !== null) {
        selectorOpen.value = false;
    }
});

/** The one-line version of step 1, shown once it is folded away. */
const choiceSummary = computed(() =>
    [cohortLabel.value, sectionOptions.find((option) => option.value === section.value)?.label, majorLabel.value, branchShortLabel.value]
        .filter((part) => part)
        .join(' · '),
);

const sectionOptions: Option[] = [
    { value: 'men', label: 'شطر الطلاب' },
    { value: 'women', label: 'شطر الطالبات' },
];

/* ------------------------------------------------------------------ */
/* The message each step hands to WhatsApp or Telegram                 */
/* ------------------------------------------------------------------ */

/**
 * The same four answers, in the form the join message writes them. Built here
 * because this is where the labels live: a step below knows its supervisors,
 * not what the student picked two steps up.
 */
const studentDetails = computed<StudentDetails>(() => ({
    cohort: cohortLabel.value,
    section: sectionOptions.find((option) => option.value === section.value)?.label ?? '',
    major: majorLabel.value,
    branch: branchOption.value?.label ?? '',
}));

/**
 * The request each step drafts, short of who it is addressed to — the step
 * completes it with whichever supervisor the student ends up tapping.
 *
 * The group is named the way it is asked for in a sentence: «القروب العام» is a
 * name already, «علوم الحاسب» is a programme and needs the word.
 */
const generalJoin = computed<Omit<JoinRequest, 'supervisor'>>(() => ({ ...studentDetails.value, group: 'القروب العام' }));

const programmeJoin = computed<Omit<JoinRequest, 'supervisor'>>(() => ({ ...studentDetails.value, group: `قروب ${majorLabel.value}` }));
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
                    <h2 class="m-0 flex-1 text-lg font-bold">عرّفنا بنفسك</h2>
                    <Button v-if="!selectorOpen" variant="ghost" size="sm" @click="selectorOpen = true">تغيير</Button>
                </div>

                <!-- Folded once it is already answered: four fields is most of a phone
                     screen, and a returning student has nothing left to say here. -->
                <button
                    v-if="!selectorOpen"
                    type="button"
                    class="w-full rounded-2xl border border-border bg-card p-4 text-start text-sm shadow-sm transition-colors hover:bg-accent/40"
                    @click="selectorOpen = true"
                >
                    {{ choiceSummary }}
                </button>

                <div v-else class="space-y-4 rounded-2xl border border-border bg-card p-4 shadow-sm sm:p-5">
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
            <section v-if="hasAnswered && activeCohort?.requirements.length" class="space-y-4">
                <div class="flex items-center gap-3">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground">
                        {{ arabicDigits(2) }}
                    </span>
                    <h2 class="m-0 text-lg font-bold">جهّز هذي قبل ما تراسل</h2>
                </div>

                <div class="rounded-2xl border border-border bg-card p-4 shadow-sm sm:p-5">
                    <JoinChecklist :requirements="activeCohort.requirements" />
                </div>
            </section>

            <!-- 3 · the two groups as a sequence, never as a pair of options:
                 stacked (side-by-side reads as «pick one»), numbered, one open at a time. -->
            <section v-if="hasAnswered" class="space-y-4">
                <div class="flex items-center gap-3">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground">
                        {{ arabicDigits(3) }}
                    </span>
                    <h2 class="m-0 text-lg font-bold">انضم للقروبات</h2>
                </div>

                <div class="space-y-3">
                    <JoinStep
                        :order="1"
                        title="القروب العام"
                        purpose="لكل طلاب كلية الحاسبات بالدفعة"
                        :subtitle="cohortLabel"
                        :section="generalSection"
                        :join="generalJoin"
                        :open="openStep === GENERAL_STEP"
                        @toggle="toggleStep(GENERAL_STEP)"
                    >
                        <template #empty>
                            <span v-if="!globalGroup">لا يوجد قروب عام لهذه الدفعة — اكتفِ بقروب تخصصك.</span>
                            <span v-else>لا يوجد مشرف متاح في القروب العام حالياً. جرّب زيارة الصفحة لاحقاً.</span>
                        </template>
                    </JoinStep>

                    <JoinStep
                        :order="2"
                        :title="majorLabel"
                        purpose="لطلاب تخصصك في دفعتك وفرعك"
                        :subtitle="programmeSubtitle"
                        :section="programmeSection"
                        :join="programmeJoin"
                        :open="openStep === PROGRAMME_STEP"
                        @toggle="toggleStep(PROGRAMME_STEP)"
                    >
                        <template #empty>
                            <span v-if="!programmeGroup && branchesOfferingMajor.length" class="block space-y-3">
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
                    </JoinStep>
                </div>
            </section>
        </div>
    </DocsLayout>
</template>
