<script setup lang="ts">
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import CoursesTab from '@/components/manage/tutors/CoursesTab.vue';
import TutorsTab from '@/components/manage/tutors/TutorsTab.vue';
import type { CourseRow, TutorRow } from '@/components/manage/tutors/types';
import { Button } from '@/components/ui/button';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

defineProps<{
    tutors: TutorRow[];
    courses: CourseRow[];
}>();

type TabName = 'tutors' | 'courses';

const page = usePage();

/** Tab state lives in the URL query so back/refresh/deep-link all work. */
const activeTab = computed<TabName>(() => {
    const query = new URLSearchParams(page.url.split('?')[1] ?? '');

    return query.get('tab') === 'courses' ? 'courses' : 'tutors';
});

function setTab(tab: TabName): void {
    router.replace({
        url: tab === 'courses' ? '/manage/tutors?tab=courses' : '/manage/tutors',
        preserveState: true,
        preserveScroll: true,
    });
}

const tabs: { name: TabName; label: string }[] = [
    { name: 'tutors', label: 'الخصوصيون' },
    { name: 'courses', label: 'المقررات' },
];

const tutorsTab = ref<InstanceType<typeof TutorsTab> | null>(null);
const coursesTab = ref<InstanceType<typeof CoursesTab> | null>(null);
</script>

<template>
    <Head title="الخصوصيون" />
    <PageHeader title="الخصوصيون" description="إدارة الخصوصيين ومقرراتهم المعروضة في الموقع العام">
        <template #actions>
            <Button v-if="activeTab === 'tutors'" @click="tutorsTab?.openCreate()">
                <Plus />
                إضافة خصوصي
            </Button>
            <Button v-else @click="coursesTab?.openCreate()">
                <Plus />
                إضافة مقرر
            </Button>
        </template>
    </PageHeader>

    <div role="tablist" aria-label="أقسام الخصوصيين" class="mb-4 flex w-fit gap-1 rounded-lg bg-muted p-1">
        <button
            v-for="tab in tabs"
            :key="tab.name"
            type="button"
            role="tab"
            :aria-selected="activeTab === tab.name"
            class="rounded-md px-4 py-1.5 text-sm font-medium transition-colors"
            :class="activeTab === tab.name ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
            @click="setTab(tab.name)"
        >
            {{ tab.label }}
            <span class="text-xs text-muted-foreground">({{ tab.name === 'tutors' ? tutors.length : courses.length }})</span>
        </button>
    </div>

    <TutorsTab v-show="activeTab === 'tutors'" ref="tutorsTab" :tutors="tutors" :courses="courses" />
    <CoursesTab v-show="activeTab === 'courses'" ref="coursesTab" :courses="courses" />
</template>
