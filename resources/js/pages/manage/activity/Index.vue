<script setup lang="ts">
import ChangesDiff from '@/components/manage/activity/ChangesDiff.vue';
import EventBadge from '@/components/manage/activity/EventBadge.vue';
import {
    subjectTypeLabel,
    type ActivityFilterOptions,
    type ActivityFilters,
    type ActivityRow,
    type Paginated,
} from '@/components/manage/activity/types';
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import Pagination from '@/components/manage/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDateTime, formatRelativeTime } from '@/lib/formatters';
import { Head, router } from '@inertiajs/vue3';
import { Activity, ChevronDown, FilterX } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    activities: Paginated<ActivityRow>;
    filters: ActivityFilters;
    filterOptions: ActivityFilterOptions;
}>();

/** Sentinel for "no filter" — reka-ui selects reserve the empty string. */
const ALL = 'all';

const hasActiveFilters = computed(() => Boolean(props.filters.log_name || props.filters.event || props.filters.subject_type));

function visit(filters: ActivityFilters, page?: number): void {
    const query: Record<string, string | number> = {};

    for (const key of ['log_name', 'event', 'subject_type'] as const) {
        const value = filters[key];

        if (value) {
            query[key] = value;
        }
    }

    if (page && page > 1) {
        query.page = page;
    }

    router.get('/manage/activity', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['activities', 'filters'],
    });
}

function applyFilter(key: keyof ActivityFilters, value: unknown): void {
    expandedId.value = null;
    visit({ ...props.filters, [key]: value === ALL ? null : String(value) });
}

function resetFilters(): void {
    expandedId.value = null;
    visit({ log_name: null, event: null, subject_type: null });
}

function goToPage(page: number): void {
    expandedId.value = null;
    visit(props.filters, page);
}

const expandedId = ref<number | null>(null);

function toggleRow(activity: ActivityRow): void {
    expandedId.value = expandedId.value === activity.id ? null : activity.id;
}

function subjectLabel(activity: ActivityRow): string | null {
    if (!activity.subject_type) {
        return null;
    }

    const type = subjectTypeLabel(activity.subject_type);

    return activity.subject_title ? `${type}: ${activity.subject_title}` : `${type} #${activity.subject_id ?? '؟'}`;
}
</script>

<template>
    <Head title="سجل النشاط" />
    <PageHeader title="سجل النشاط" description="تتبّع التغييرات على المحتوى والمستخدمين" />

    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <Select :model-value="filters.log_name ?? ALL" @update:model-value="(value) => applyFilter('log_name', value)">
                <SelectTrigger class="w-40" aria-label="تصفية باسم السجل">
                    <SelectValue placeholder="اسم السجل" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">كل السجلات</SelectItem>
                    <SelectItem v-for="name in filterOptions.logNames" :key="name" :value="name">{{ name }}</SelectItem>
                </SelectContent>
            </Select>

            <Select :model-value="filters.event ?? ALL" @update:model-value="(value) => applyFilter('event', value)">
                <SelectTrigger class="w-40" aria-label="تصفية بالحدث">
                    <SelectValue placeholder="الحدث" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">كل الأحداث</SelectItem>
                    <SelectItem v-for="event in filterOptions.events" :key="event" :value="event">
                        {{ event }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select :model-value="filters.subject_type ?? ALL" @update:model-value="(value) => applyFilter('subject_type', value)">
                <SelectTrigger class="w-40" aria-label="تصفية بنوع الموضوع">
                    <SelectValue placeholder="نوع الموضوع" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">كل الأنواع</SelectItem>
                    <SelectItem v-for="type in filterOptions.subjectTypes" :key="type" :value="type">
                        {{ subjectTypeLabel(type) }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Button v-if="hasActiveFilters" variant="ghost" size="sm" class="text-muted-foreground" @click="resetFilters">
                <FilterX class="size-4" />
                إعادة التعيين
            </Button>
        </div>

        <EmptyState
            v-if="!activities.data.length && !hasActiveFilters"
            :icon="Activity"
            title="لا يوجد نشاط بعد"
            description="ستظهر هنا التغييرات على الصفحات والمستخدمين فور حدوثها."
        />

        <p v-else-if="!activities.data.length" class="py-8 text-center text-sm text-muted-foreground">لا نتائج مطابقة للتصفية الحالية.</p>

        <ul v-else class="overflow-hidden rounded-lg border border-border">
            <li v-for="activity in activities.data" :key="activity.id" class="border-b border-border last:border-b-0">
                <button
                    type="button"
                    class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 p-3 text-start hover:bg-muted/50"
                    :aria-expanded="expandedId === activity.id"
                    @click="toggleRow(activity)"
                >
                    <EventBadge :event="activity.event" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">
                            {{ subjectLabel(activity) ?? activity.description }}
                        </span>
                        <span class="block truncate text-xs text-muted-foreground">
                            <template v-if="activity.causer_name">{{ activity.causer_name }} · </template>
                            <template v-if="activity.log_name">{{ activity.log_name }} · </template>
                            {{ activity.description }}
                        </span>
                    </span>
                    <span v-if="activity.created_at" class="shrink-0 text-xs text-muted-foreground" :title="formatDateTime(activity.created_at)">
                        {{ formatRelativeTime(activity.created_at) }}
                    </span>
                    <ChevronDown
                        class="size-4 shrink-0 text-muted-foreground transition-transform"
                        :class="{ 'rotate-180': expandedId === activity.id }"
                        aria-hidden="true"
                    />
                </button>

                <div v-if="expandedId === activity.id" class="border-t border-border bg-muted/30 p-3">
                    <ChangesDiff v-if="activity.changes" :changes="activity.changes" />
                    <p v-else class="text-sm text-muted-foreground">لا توجد تغييرات مسجّلة لهذا النشاط.</p>
                </div>
            </li>
        </ul>

        <Pagination :page="activities.current_page" :pages="activities.last_page" :total="activities.total" @update:page="goToPage" />
    </div>
</template>
