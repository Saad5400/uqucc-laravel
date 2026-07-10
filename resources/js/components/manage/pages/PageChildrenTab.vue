<script setup lang="ts">
import EmptyState from '@/components/manage/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useSortableList } from '@/composables/useSortableList';
import { Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, FolderTree, GripVertical, Plus } from 'lucide-vue-next';
import { ref } from 'vue';
import type { ChildPageRow, PageWorkspace } from './types';

const props = defineProps<{
    page: PageWorkspace;
    children: ChildPageRow[];
}>();

const emit = defineEmits<{
    addChild: [];
}>();

const reorderError = ref<string | null>(null);

const { items, draggingId, startDrag, dragOver, endDrag, moveUp, moveDown } = useSortableList(
    () => props.children,
    (ids) =>
        new Promise<void>((resolve, reject) => {
            router.post(
                '/manage/pages/reorder',
                { parent_id: props.page.id, ids },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        reorderError.value = null;
                        resolve();
                    },
                    onError: () => {
                        reorderError.value = 'تعذر حفظ الترتيب. أعيد الترتيب السابق.';
                        reject(new Error('reorder failed'));
                    },
                },
            );
        }),
);
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <EmptyState
            v-if="!children.length"
            :icon="FolderTree"
            title="لا توجد صفحات فرعية"
            description="الصفحات الفرعية تظهر تحت هذه الصفحة في شجرة الموقع وقائمته الجانبية."
        >
            <Button @click="emit('addChild')">
                <Plus />
                إضافة صفحة فرعية
            </Button>
        </EmptyState>

        <template v-else>
            <div class="flex justify-end">
                <Button variant="outline" size="sm" @click="emit('addChild')">
                    <Plus />
                    إضافة صفحة فرعية
                </Button>
            </div>

            <p v-if="reorderError" class="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive-foreground">
                {{ reorderError }}
            </p>

            <ul class="overflow-hidden rounded-lg border border-border">
                <li
                    v-for="child in items"
                    :key="child.id"
                    class="flex items-center gap-2 border-b border-border p-3 transition-opacity last:border-b-0"
                    :class="{ 'opacity-50': draggingId === child.id }"
                    draggable="true"
                    @dragstart="startDrag(child, $event)"
                    @dragover="dragOver(child, $event)"
                    @dragend="endDrag($event)"
                    @drop.prevent
                >
                    <GripVertical class="size-4 shrink-0 cursor-grab text-muted-foreground/60" aria-hidden="true" />
                    <Link
                        :href="`/manage/pages/${child.id}/edit`"
                        draggable="false"
                        class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1"
                    >
                        <span class="truncate font-medium">{{ child.title }}</span>
                        <span dir="ltr" class="truncate text-xs text-muted-foreground">{{ child.slug }}</span>
                        <Badge v-if="child.hidden" variant="secondary">مخفي</Badge>
                        <span v-if="child.children_count" class="text-xs text-muted-foreground">({{ child.children_count }})</span>
                    </Link>
                    <Button variant="ghost" size="icon-sm" :aria-label="`نقل ${child.title} لأعلى`" @click="moveUp(child)">
                        <ArrowUp />
                    </Button>
                    <Button variant="ghost" size="icon-sm" :aria-label="`نقل ${child.title} لأسفل`" @click="moveDown(child)">
                        <ArrowDown />
                    </Button>
                </li>
            </ul>
        </template>
    </div>
</template>
