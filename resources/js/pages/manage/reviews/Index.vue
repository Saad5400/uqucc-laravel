<script setup lang="ts">
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import type { PendingReviewRow } from '@/components/manage/reviews/types';
import { Badge } from '@/components/ui/badge';
import { formatRelativeTime } from '@/lib/formatters';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, ListChecks } from 'lucide-vue-next';

defineOptions({ layout: ManageLayout });

defineProps<{
    pending: PendingReviewRow[];
}>();
</script>

<template>
    <Head title="المراجعات" />
    <PageHeader title="المراجعات" description="تعديلات المحررين التي تنتظر الاعتماد قبل نشرها على الموقع" />

    <EmptyState
        v-if="!pending.length"
        :icon="ListChecks"
        title="لا توجد تعديلات بانتظار المراجعة"
        description="عندما يحفظ محرر خاضع للمراجعة تعديلاً على صفحة، سيظهر هنا لتعتمده أو ترفضه قبل نشره."
    />

    <ul v-else class="overflow-hidden rounded-lg border border-border">
        <li v-for="request in pending" :key="request.id">
            <Link
                :href="`/manage/reviews/${request.id}`"
                class="flex items-center gap-3 border-b border-border p-3 transition-colors last:border-b-0 hover:bg-accent"
            >
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="truncate font-medium">{{ request.page?.title ?? 'صفحة محذوفة' }}</span>
                        <Badge v-if="request.page?.trashed" variant="destructive">محذوفة</Badge>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <span v-if="request.author_name">بواسطة {{ request.author_name }}</span>
                        <span v-if="request.updated_at">· {{ formatRelativeTime(request.updated_at) }}</span>
                    </div>
                    <div v-if="request.changed_fields.length" class="flex flex-wrap gap-1 pt-0.5">
                        <Badge v-for="field in request.changed_fields" :key="field" variant="secondary">{{ field }}</Badge>
                    </div>
                </div>
                <ChevronLeft class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            </Link>
        </li>
    </ul>
</template>
