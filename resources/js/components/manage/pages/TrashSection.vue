<script setup lang="ts">
import EmptyState from '@/components/manage/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { ArchiveRestore, Loader2, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import ForceDeleteDialog from './ForceDeleteDialog.vue';
import type { TrashedPageRow } from './types';

defineProps<{
    pages: TrashedPageRow[];
}>();

const restoringId = ref<number | null>(null);

function restore(page: TrashedPageRow): void {
    restoringId.value = page.id;

    router.post(
        `/manage/pages/${page.id}/restore`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                restoringId.value = null;
            },
        },
    );
}

const forceDeleting = ref<TrashedPageRow | null>(null);
const forceDialogOpen = ref(false);

function confirmForceDelete(page: TrashedPageRow): void {
    forceDeleting.value = page;
    forceDialogOpen.value = true;
}
</script>

<template>
    <section aria-label="الصفحات المحذوفة" class="space-y-3">
        <EmptyState v-if="!pages.length" :icon="Trash2" title="لا توجد صفحات محذوفة" description="الصفحات التي تحذفها تظهر هنا ويمكن استعادتها." />

        <ul v-else class="overflow-hidden rounded-lg border border-border">
            <li v-for="page in pages" :key="page.id" class="flex flex-wrap items-center gap-2 border-b border-border p-3 last:border-b-0">
                <div class="min-w-0 flex-1 space-y-0.5">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-medium">{{ page.title }}</span>
                        <span dir="ltr" class="truncate text-xs text-muted-foreground">{{ page.slug }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="page.parent_title">ضمن «{{ page.parent_title }}» · </template>
                        حُذفت في {{ page.deleted_at }}
                    </p>
                </div>
                <div class="flex items-center gap-1">
                    <Button variant="outline" size="sm" :disabled="restoringId === page.id" @click="restore(page)">
                        <Loader2 v-if="restoringId === page.id" class="size-4 animate-spin" />
                        <ArchiveRestore v-else class="size-4" />
                        استعادة
                    </Button>
                    <Button variant="ghost" size="sm" class="text-destructive-foreground" @click="confirmForceDelete(page)">
                        <Trash2 class="size-4" />
                        حذف نهائي
                    </Button>
                </div>
            </li>
        </ul>

        <ForceDeleteDialog v-model:open="forceDialogOpen" :page="forceDeleting" />
    </section>
</template>
