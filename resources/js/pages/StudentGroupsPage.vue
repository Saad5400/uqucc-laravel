<script setup lang="ts">
import GroupPanel from '@/components/groups/GroupPanel.vue';
import type { Cohort, SectionFilter, StudentGroup } from '@/components/groups/types';
import { sectionMatchesFilter } from '@/components/groups/types';
import DocsLayout from '@/components/layout/DocsLayout.vue';
import PageHeader from '@/components/page/PageHeader.vue';
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { arabicSupervisors, normalizeArabic } from '@/lib/arabic';
import { CheckCircle2, ChevronDown, ClipboardCheck, MessagesSquare, Search, TriangleAlert } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

defineOptions({ layout: false });

interface Props {
    cohorts: Cohort[];
    page?: { html_content: unknown; title?: string };
    hasContent?: boolean;
    seo: SeoData;
}

const props = withDefaults(defineProps<Props>(), { hasContent: false });

const SECTION_FILTER_KEY = 'uqucc-section-filter';

const activeCohortId = ref<number | null>(props.cohorts[0]?.id ?? null);
const sectionFilter = ref<SectionFilter>('all');
const expandedGroupId = ref<number | null>(null);
const search = ref('');

const sectionOptions: { value: SectionFilter; label: string }[] = [
    { value: 'all', label: 'الكل' },
    { value: 'men', label: 'طلاب' },
    { value: 'women', label: 'طالبات' },
];

/**
 * Restore where the visitor was: the section they picked last time (it rarely
 * changes — nobody switches campus section between visits) and, if they arrived
 * on a shared link, the exact intake and group it points at.
 *
 * Both are read after mount rather than during render: this page is prerendered
 * on the server, where neither `localStorage` nor the query string exists.
 */
onMounted(() => {
    const stored = localStorage.getItem(SECTION_FILTER_KEY);

    if (stored === 'men' || stored === 'women' || stored === 'all') {
        sectionFilter.value = stored;
    }

    const params = new URLSearchParams(window.location.search);
    const cohortId = Number(params.get('cohort'));
    const groupId = Number(params.get('group'));

    if (props.cohorts.some((cohort) => cohort.id === cohortId)) {
        activeCohortId.value = cohortId;
    }

    if (activeCohort.value?.groups.some((group) => group.id === groupId)) {
        expandedGroupId.value = groupId;
    }
});

watch(sectionFilter, (value) => localStorage.setItem(SECTION_FILTER_KEY, value));

/** Keep the address bar shareable without asking the server for anything. */
watch([activeCohortId, expandedGroupId], ([cohortId, groupId]) => {
    const params = new URLSearchParams();

    if (cohortId !== null) {
        params.set('cohort', String(cohortId));
    }

    if (groupId !== null) {
        params.set('group', String(groupId));
    }

    const query = params.toString();
    window.history.replaceState(null, '', query === '' ? window.location.pathname : `?${query}`);
});

const activeCohort = computed(() => props.cohorts.find((cohort) => cohort.id === activeCohortId.value) ?? null);

function selectCohort(cohort: Cohort): void {
    activeCohortId.value = cohort.id;
    expandedGroupId.value = null;
    search.value = '';
}

/** How many supervisors of this group the current section filter leaves visible. */
function visibleCount(group: StudentGroup): number {
    return group.sections
        .filter((section) => sectionMatchesFilter(section.key, sectionFilter.value))
        .reduce((sum, section) => sum + section.supervisors.length, 0);
}

/** A group the filter has emptied is noise — the point of the filter is fewer rows. */
function passesFilter(group: StudentGroup): boolean {
    return sectionFilter.value === 'all' || visibleCount(group) > 0;
}

const generalGroups = computed(() => (activeCohort.value?.groups ?? []).filter((group) => group.is_general && passesFilter(group)));

/** Search covers what a student knows about themselves — programme and branch, never a supervisor's name. */
function matchesSearch(group: StudentGroup): boolean {
    const query = normalizeArabic(search.value);

    if (query === '') {
        return true;
    }

    return normalizeArabic(`${group.name} ${group.branch_label}`).includes(query);
}

interface BranchBucket {
    key: string;
    label: string;
    groups: StudentGroup[];
}

const specializedByBranch = computed<BranchBucket[]>(() => {
    const buckets = new Map<string, BranchBucket>();

    for (const group of activeCohort.value?.groups ?? []) {
        if (group.is_general || !passesFilter(group) || !matchesSearch(group)) {
            continue;
        }

        const key = group.branch ?? 'other';

        if (!buckets.has(key)) {
            buckets.set(key, { key, label: group.branch_label, groups: [] });
        }

        buckets.get(key)!.groups.push(group);
    }

    return [...buckets.values()];
});

const hasSpecialized = computed(() => (activeCohort.value?.groups ?? []).some((group) => !group.is_general));

function toggleGroup(group: StudentGroup): void {
    expandedGroupId.value = expandedGroupId.value === group.id ? null : group.id;
}
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
            class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border px-6 py-14 text-center"
        >
            <div class="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <MessagesSquare class="size-6" />
            </div>
            <div class="space-y-1">
                <p class="font-medium">لا توجد قروبات معلنة حالياً</p>
                <p class="text-sm text-muted-foreground">ستظهر هنا قروبات الدفعات ومشرفوها بمجرد إضافتها. تابع الصفحة مع بداية الفصل الدراسي.</p>
            </div>
        </div>

        <div v-else class="space-y-6">
            <!-- Which intake, then which section — the two things a student knows about themselves -->
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <div role="tablist" aria-label="الدفعة" class="flex flex-wrap items-center gap-1 rounded-lg border bg-muted/50 p-1">
                    <Button
                        v-for="cohort in cohorts"
                        :key="cohort.id"
                        role="tab"
                        :aria-selected="activeCohortId === cohort.id"
                        size="sm"
                        :variant="activeCohortId === cohort.id ? 'default' : 'ghost'"
                        @click="selectCohort(cohort)"
                    >
                        {{ cohort.name }}
                    </Button>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-sm text-muted-foreground">الشطر</span>
                    <div role="group" aria-label="الشطر" class="flex items-center gap-1 rounded-lg border bg-muted/50 p-1">
                        <Button
                            v-for="option in sectionOptions"
                            :key="option.value"
                            size="sm"
                            :aria-pressed="sectionFilter === option.value"
                            :variant="sectionFilter === option.value ? 'default' : 'ghost'"
                            @click="sectionFilter = option.value"
                        >
                            {{ option.label }}
                        </Button>
                    </div>
                </div>
            </div>

            <template v-if="activeCohort">
                <p v-if="activeCohort.description" class="m-0 leading-relaxed text-muted-foreground">{{ activeCohort.description }}</p>

                <div v-if="activeCohort.requirements.length" class="rounded-xl border border-primary/20 bg-primary/5 p-4">
                    <p class="mb-3 flex items-center gap-2 text-sm font-semibold">
                        <ClipboardCheck class="size-4 shrink-0 text-primary" aria-hidden="true" />
                        قبل ما تراسل، جهّز:
                    </p>
                    <ul class="m-0 list-none space-y-2 p-0">
                        <li v-for="(requirement, index) in activeCohort.requirements" :key="index" class="flex items-start gap-2 text-sm">
                            <CheckCircle2 class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                            <span>{{ requirement }}</span>
                        </li>
                    </ul>
                </div>

                <p
                    v-if="activeCohort.note"
                    class="flex items-start gap-2 rounded-xl border border-border bg-muted/40 p-3 text-sm text-muted-foreground"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{{ activeCohort.note }}</span>
                </p>

                <!-- The general group: the answer for anyone who does not know their programme yet -->
                <section v-if="generalGroups.length" class="space-y-4">
                    <div v-for="group in generalGroups" :key="group.id" class="space-y-4 rounded-xl border border-border bg-background/40 p-4 sm:p-6">
                        <h2 class="m-0 text-xl font-bold">القروب العام</h2>
                        <GroupPanel :group="group" :filter="sectionFilter" />
                    </div>
                </section>

                <section v-if="hasSpecialized" class="space-y-4">
                    <div class="space-y-1">
                        <h2 class="m-0 text-xl font-bold">قروب تخصصك</h2>
                        <p class="m-0 text-sm text-muted-foreground">اختر تخصصك تحت فرعك لتظهر لك قائمة مشرفيه.</p>
                    </div>

                    <div class="relative">
                        <Search class="absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" type="search" placeholder="ابحث بالتخصص أو الفرع…" class="ps-10" aria-label="ابحث بالتخصص أو الفرع" />
                    </div>

                    <p v-if="!specializedByBranch.length" class="py-8 text-center text-sm text-muted-foreground">
                        لا توجد نتائج مطابقة. جرّب اسم تخصص أو فرع آخر.
                    </p>

                    <div v-for="branch in specializedByBranch" :key="branch.key" class="space-y-2">
                        <h3 class="text-sm font-medium text-muted-foreground">{{ branch.label }}</h3>
                        <ul class="m-0 list-none space-y-2 p-0">
                            <li v-for="group in branch.groups" :key="group.id" class="overflow-hidden rounded-xl border border-border">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-3 p-4 text-start transition-colors hover:bg-accent/50"
                                    :aria-expanded="expandedGroupId === group.id"
                                    @click="toggleGroup(group)"
                                >
                                    <ChevronDown
                                        class="size-4 shrink-0 text-muted-foreground transition-transform"
                                        :class="expandedGroupId === group.id ? 'rotate-180' : ''"
                                        aria-hidden="true"
                                    />
                                    <span class="flex-1 font-medium">{{ group.name }}</span>
                                    <span class="shrink-0 text-xs text-muted-foreground tabular-nums">
                                        {{ arabicSupervisors(visibleCount(group)) }}
                                    </span>
                                </button>
                                <div v-if="expandedGroupId === group.id" class="border-t border-border p-4">
                                    <GroupPanel :group="group" :filter="sectionFilter" />
                                </div>
                            </li>
                        </ul>
                    </div>
                </section>
            </template>
        </div>
    </DocsLayout>
</template>
