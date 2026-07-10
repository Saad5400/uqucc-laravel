<script setup lang="ts">
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import { Head } from '@inertiajs/vue3';
import { LayoutDashboard } from 'lucide-vue-next';

defineOptions({ layout: ManageLayout });
</script>

<template>
    <Head title="لوحة التحكم" />
    <PageHeader title="لوحة التحكم" description="نظرة عامة على الموقع" />
    <EmptyState
        :icon="LayoutDashboard"
        title="لا توجد إحصاءات بعد"
        description="ستظهر هنا نظرة عامة على الصفحات والزيارات وأوامر البوت في مرحلة لاحقة من إعادة البناء."
    />
</template>
