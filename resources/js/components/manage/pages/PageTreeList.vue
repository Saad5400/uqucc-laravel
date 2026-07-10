<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useSortableList } from '@/composables/useSortableList';
import { Icon } from '@iconify/vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ChevronDown, ChevronLeft, EllipsisVertical, GripVertical, Plus, Trash2 } from 'lucide-vue-next';
import { computed, inject, ref } from 'vue';
import { pageTreeContextKey, type PageTreeNode } from './types';

const props = defineProps<{
    nodes: PageTreeNode[];
    parentId: number | null;
    depth: number;
}>();

const context = inject(pageTreeContextKey)!;

const reorderError = ref<string | null>(null);

const { items, draggingId, startDrag, dragOver, endDrag, moveUp, moveDown } = useSortableList(
    () => props.nodes,
    (ids) =>
        new Promise<void>((resolve, reject) => {
            router.post(
                '/manage/pages/reorder',
                { parent_id: props.parentId, ids },
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

/** While a filter narrows the tree, render the filtered nodes as-is (drag is disabled). */
const rows = computed(() => (context.isFiltering.value ? props.nodes : items.value));

function showsChildren(node: PageTreeNode): boolean {
    return node.children.length > 0 && (context.isFiltering.value || context.isExpanded(node.id));
}
</script>

<template>
    <div>
        <p v-if="reorderError" class="mb-1 rounded-md border border-destructive/50 bg-destructive/10 px-3 py-1.5 text-xs text-destructive-foreground">
            {{ reorderError }}
        </p>
        <ul :class="depth === 0 ? 'overflow-hidden rounded-lg border border-border' : ''">
            <li v-for="node in rows" :key="node.id" :class="depth === 0 ? 'border-b border-border last:border-b-0' : ''">
                <div
                    class="group flex items-center gap-1.5 py-2 pe-2 transition-colors hover:bg-accent/50"
                    :class="{ 'opacity-50': draggingId === node.id }"
                    :style="{ paddingInlineStart: `${0.5 + depth * 1.5}rem` }"
                    :draggable="!context.isFiltering.value"
                    @dragstart="startDrag(node, $event)"
                    @dragover="dragOver(node, $event)"
                    @dragend="endDrag($event)"
                    @drop.prevent
                >
                    <GripVertical v-if="!context.isFiltering.value" class="size-4 shrink-0 cursor-grab text-muted-foreground/60" aria-hidden="true" />
                    <Button
                        v-if="node.children.length"
                        variant="ghost"
                        size="icon-sm"
                        class="size-6 shrink-0"
                        :aria-label="context.isExpanded(node.id) ? `طي ${node.title}` : `توسيع ${node.title}`"
                        :aria-expanded="showsChildren(node)"
                        :disabled="context.isFiltering.value"
                        @click.stop="context.toggleExpanded(node.id)"
                    >
                        <ChevronDown v-if="showsChildren(node)" class="size-4" />
                        <ChevronLeft v-else class="size-4" />
                    </Button>
                    <span v-else class="size-6 shrink-0" aria-hidden="true" />

                    <Icon v-if="node.icon" :icon="node.icon" class="size-4 shrink-0 text-muted-foreground" />

                    <Link
                        :href="`/manage/pages/${node.id}/edit`"
                        draggable="false"
                        class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1"
                    >
                        <span class="truncate font-medium">{{ node.title }}</span>
                        <span dir="ltr" class="truncate text-xs text-muted-foreground">{{ node.slug }}</span>
                        <Badge v-if="node.hidden" variant="secondary">مخفي</Badge>
                        <Badge v-if="node.hidden_from_bot" variant="outline">مخفي من البوت</Badge>
                        <Badge v-if="!node.has_content" variant="outline" class="border-amber-500/60 text-amber-600 dark:text-amber-400">
                            بلا محتوى
                        </Badge>
                        <span v-if="node.children_count" class="text-xs text-muted-foreground">({{ node.children_count }})</span>
                    </Link>

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="ghost" size="icon-sm" :aria-label="`إجراءات ${node.title}`">
                                <EllipsisVertical />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem @select="context.openCreateChild(node.id)">
                                <Plus />
                                إضافة صفحة فرعية
                            </DropdownMenuItem>
                            <DropdownMenuItem :disabled="context.isFiltering.value" @select="moveUp(node)">
                                <ArrowUp />
                                نقل لأعلى
                            </DropdownMenuItem>
                            <DropdownMenuItem :disabled="context.isFiltering.value" @select="moveDown(node)">
                                <ArrowDown />
                                نقل لأسفل
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem variant="destructive" @select="context.confirmDelete(node)">
                                <Trash2 />
                                حذف
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <PageTreeList v-if="showsChildren(node)" :nodes="node.children" :parent-id="node.id" :depth="depth + 1" />
            </li>
        </ul>
    </div>
</template>
