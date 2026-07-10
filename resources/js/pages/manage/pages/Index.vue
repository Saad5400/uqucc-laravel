<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import PageCreateDialog from '@/components/manage/pages/PageCreateDialog.vue';
import PageTreeList from '@/components/manage/pages/PageTreeList.vue';
import TrashSection from '@/components/manage/pages/TrashSection.vue';
import { pageTreeContextKey, type PageTreeNode, type ParentOption, type TrashedPageRow } from '@/components/manage/pages/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Deferred, Head, router, usePoll } from '@inertiajs/vue3';
import { FileText, Plus, Trash2, X } from 'lucide-vue-next';
import { computed, provide, ref } from 'vue';

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    pages: PageTreeNode[];
    trashedPages?: TrashedPageRow[];
}>();

/** Refresh the tree every minute; polling throttles automatically in hidden tabs. */
usePoll(60_000, { only: ['pages'] });

/* ------------------------------------------------------------------ */
/* Expansion state (persisted)                                         */
/* ------------------------------------------------------------------ */

const EXPANDED_STORAGE_KEY = 'manage.pages.tree.expanded';

function loadExpandedIds(): Set<number> {
    try {
        const raw = localStorage.getItem(EXPANDED_STORAGE_KEY);

        return new Set(raw ? (JSON.parse(raw) as number[]) : []);
    } catch {
        return new Set();
    }
}

const expandedIds = ref<Set<number>>(loadExpandedIds());

function toggleExpanded(id: number): void {
    const next = new Set(expandedIds.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    expandedIds.value = next;

    try {
        localStorage.setItem(EXPANDED_STORAGE_KEY, JSON.stringify([...next]));
    } catch {
        /* storage unavailable — expansion just won't persist */
    }
}

/* ------------------------------------------------------------------ */
/* Search + filter chips                                               */
/* ------------------------------------------------------------------ */

type FilterChip = 'all' | 'hidden' | 'no-content';

const search = ref('');
const filterChip = ref<FilterChip>('all');

const filterChips: { name: FilterChip; label: string }[] = [
    { name: 'all', label: 'الكل' },
    { name: 'hidden', label: 'المخفية' },
    { name: 'no-content', label: 'بلا محتوى' },
];

const isFiltering = computed(() => search.value.trim() !== '' || filterChip.value !== 'all');

function nodeMatches(node: PageTreeNode): boolean {
    const query = search.value.trim();

    if (query !== '' && !node.title.includes(query) && !node.slug.includes(query)) {
        return false;
    }

    if (filterChip.value === 'hidden') {
        return node.hidden;
    }

    if (filterChip.value === 'no-content') {
        return !node.has_content;
    }

    return true;
}

/** Keep matching nodes along with their ancestors so hits stay in context. */
function filterTree(nodes: PageTreeNode[]): PageTreeNode[] {
    return nodes.flatMap((node) => {
        const children = filterTree(node.children);

        if (nodeMatches(node) || children.length > 0) {
            return [{ ...node, children }];
        }

        return [];
    });
}

const visibleTree = computed(() => (isFiltering.value ? filterTree(props.pages) : props.pages));

/* ------------------------------------------------------------------ */
/* Create dialog                                                       */
/* ------------------------------------------------------------------ */

const createDialogOpen = ref(false);
const createParentId = ref<number | null>(null);

function openCreate(parentId: number | null = null): void {
    createParentId.value = parentId;
    createDialogOpen.value = true;
}

/** Flat depth-first list of the live tree, reused by the create dialog's parent picker. */
const parentOptions = computed<ParentOption[]>(() => {
    const options: ParentOption[] = [];

    const walk = (nodes: PageTreeNode[], level: number): void => {
        for (const node of nodes) {
            options.push({ id: node.id, title: node.title, level });
            walk(node.children, level + 1);
        }
    };

    walk(props.pages, 0);

    return options;
});

/* ------------------------------------------------------------------ */
/* Soft delete                                                         */
/* ------------------------------------------------------------------ */

const deletingNode = ref<PageTreeNode | null>(null);
const confirmingDeletion = ref(false);
const deleting = ref(false);

function countDescendants(node: PageTreeNode): number {
    return node.children.reduce((total, child) => total + 1 + countDescendants(child), 0);
}

const deletingDescendantsCount = computed(() => (deletingNode.value ? countDescendants(deletingNode.value) : 0));

function confirmDelete(node: PageTreeNode): void {
    deletingNode.value = node;
    confirmingDeletion.value = true;
}

function deletePage(): void {
    if (!deletingNode.value) {
        return;
    }

    deleting.value = true;

    router.delete(`/manage/pages/${deletingNode.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            confirmingDeletion.value = false;
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}

provide(pageTreeContextKey, {
    isFiltering,
    isExpanded: (id: number) => expandedIds.value.has(id),
    toggleExpanded,
    openCreateChild: (parentId: number) => openCreate(parentId),
    confirmDelete,
});

/* ------------------------------------------------------------------ */
/* Trash                                                               */
/* ------------------------------------------------------------------ */

const showTrash = ref(false);
</script>

<template>
    <Head title="الصفحات" />
    <PageHeader title="الصفحات" description="شجرة صفحات الموقع — التحرير والترتيب والإخفاء والحذف">
        <template #actions>
            <Button @click="openCreate(null)">
                <Plus />
                صفحة جديدة
            </Button>
        </template>
    </PageHeader>

    <div class="space-y-4">
        <div v-if="pages.length" class="flex flex-wrap items-center gap-2">
            <div class="relative w-full max-w-xs">
                <Input
                    v-model="search"
                    type="search"
                    placeholder="ابحث بالعنوان أو الرابط…"
                    class="search-input pe-8"
                    aria-label="البحث في الصفحات"
                />
                <button
                    v-if="search"
                    type="button"
                    aria-label="مسح البحث"
                    class="absolute end-2 top-1/2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground transition-colors hover:text-foreground"
                    @click="search = ''"
                >
                    <X class="size-4" />
                </button>
            </div>
            <div role="group" aria-label="تصفية الصفحات" class="flex w-fit gap-1 rounded-lg bg-muted p-1">
                <button
                    v-for="chip in filterChips"
                    :key="chip.name"
                    type="button"
                    :aria-pressed="filterChip === chip.name"
                    class="rounded-md px-3 py-1 text-sm font-medium transition-colors"
                    :class="filterChip === chip.name ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                    @click="filterChip = chip.name"
                >
                    {{ chip.label }}
                </button>
            </div>
            <p v-if="isFiltering" class="text-xs text-muted-foreground">أثناء التصفية تُعرض النتائج موسّعة، والترتيب والطي معطّلان.</p>
        </div>

        <EmptyState
            v-if="!pages.length"
            :icon="FileText"
            title="لا توجد صفحات بعد"
            description="الصفحات هي محتوى الموقع والبوت. أنشئ أول صفحة لبدء بناء الشجرة."
        >
            <Button @click="openCreate(null)">
                <Plus />
                صفحة جديدة
            </Button>
        </EmptyState>

        <p v-else-if="!visibleTree.length" class="py-8 text-center text-sm text-muted-foreground">لا نتائج مطابقة لبحثك.</p>

        <PageTreeList v-else :nodes="visibleTree" :parent-id="null" :depth="0" />

        <div class="border-t border-border pt-4">
            <Button variant="ghost" size="sm" class="text-muted-foreground" :aria-expanded="showTrash" @click="showTrash = !showTrash">
                <Trash2 class="size-4" />
                المحذوفة
            </Button>

            <div v-if="showTrash" class="mt-3">
                <Deferred data="trashedPages">
                    <template #fallback>
                        <div class="space-y-2">
                            <Skeleton class="h-12 w-full" />
                            <Skeleton class="h-12 w-full" />
                        </div>
                    </template>
                    <TrashSection :pages="trashedPages ?? []" />
                </Deferred>
            </div>
        </div>
    </div>

    <PageCreateDialog v-model:open="createDialogOpen" :parent-options="parentOptions" :preset-parent-id="createParentId" />

    <ConfirmDialog v-model:open="confirmingDeletion" title="حذف الصفحة" destructive confirm-label="حذف" :processing="deleting" @confirm="deletePage">
        <template v-if="deletingNode">
            سيتم حذف الصفحة «{{ deletingNode.title }}».
            {{ deletingDescendantsCount > 0 ? `سيتم حذف ${deletingDescendantsCount} من الصفحات الفرعية أيضًا.` : '' }}
            يمكن استعادتها لاحقًا من قسم «المحذوفة».
        </template>
    </ConfirmDialog>
</template>

<style scoped>
/* Hide the WebKit-blue native clear button; the themed X button replaces it. */
.search-input::-webkit-search-cancel-button {
    -webkit-appearance: none;
    appearance: none;
}
</style>
