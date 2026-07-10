<script setup lang="ts">
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageAuthorsTab from '@/components/manage/pages/PageAuthorsTab.vue';
import PageChildrenTab from '@/components/manage/pages/PageChildrenTab.vue';
import PageContentTab from '@/components/manage/pages/PageContentTab.vue';
import PageCreateDialog from '@/components/manage/pages/PageCreateDialog.vue';
import PageSettingsTab from '@/components/manage/pages/PageSettingsTab.vue';
import PageTelegramTab from '@/components/manage/pages/PageTelegramTab.vue';
import type {
    AttachmentInfo,
    AuthorRow,
    ChildPageRow,
    PageWorkspace,
    ParentChainItem,
    ParentOption,
    UserOption,
} from '@/components/manage/pages/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArchiveRestore, ChevronLeft, ExternalLink, Loader2, Pencil } from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref, useTemplateRef } from 'vue';

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    page: PageWorkspace;
    parentChain: ParentChainItem[];
    children: ChildPageRow[];
    authors: AuthorRow[];
    parentOptions: ParentOption[];
    descendantIds: number[];
    users: UserOption[];
    attachments: AttachmentInfo[];
    copilot: { enabled: boolean };
}>();

const inertiaPage = usePage();

/* ------------------------------------------------------------------ */
/* Tabs (state in the URL query)                                       */
/* ------------------------------------------------------------------ */

type TabName = 'content' | 'settings' | 'telegram' | 'children' | 'authors';

const tabNames: TabName[] = ['content', 'settings', 'telegram', 'children', 'authors'];

const activeTab = computed<TabName>(() => {
    const query = new URLSearchParams(inertiaPage.url.split('?')[1] ?? '');
    const tab = query.get('tab');

    return tabNames.includes(tab as TabName) ? (tab as TabName) : 'content';
});

function setTab(tab: TabName): void {
    const base = `/manage/pages/${props.page.id}/edit`;

    router.replace({
        url: tab === 'content' ? base : `${base}?tab=${tab}`,
        preserveState: true,
        preserveScroll: true,
    });
}

const tabs = computed<{ name: TabName; label: string; count?: number }[]>(() => [
    { name: 'content', label: 'المحتوى' },
    { name: 'settings', label: 'الإعدادات' },
    { name: 'telegram', label: 'تيليجرام' },
    { name: 'children', label: 'الأبناء', count: props.children.length },
    { name: 'authors', label: 'المؤلفون', count: props.authors.length },
]);

/* ------------------------------------------------------------------ */
/* Inline title editing                                                */
/* ------------------------------------------------------------------ */

const editingTitle = ref(false);
const titleDraft = ref(props.page.title);
const titleError = ref<string | null>(null);
const savingTitle = ref(false);
const titleInput = useTemplateRef('titleInput');

/** Optimistic display: the draft shows immediately while the save runs. */
const displayedTitle = computed(() => (editingTitle.value || savingTitle.value ? titleDraft.value : props.page.title));

async function startEditingTitle(): Promise<void> {
    titleDraft.value = props.page.title;
    titleError.value = null;
    editingTitle.value = true;
    await nextTick();
    titleInput.value?.focus();
    titleInput.value?.select();
}

function cancelEditingTitle(): void {
    editingTitle.value = false;
    titleDraft.value = props.page.title;
    titleError.value = null;
}

function saveTitle(): void {
    if (savingTitle.value) {
        return;
    }

    const next = titleDraft.value.trim();

    if (next === '' || next === props.page.title) {
        cancelEditingTitle();

        return;
    }

    savingTitle.value = true;
    editingTitle.value = false;

    router.put(
        `/manage/pages/${props.page.id}`,
        { title: next },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                titleError.value = null;
            },
            onError: (errors) => {
                titleDraft.value = props.page.title;
                titleError.value = errors.title ?? 'تعذر حفظ العنوان.';
            },
            onFinish: () => {
                savingTitle.value = false;
            },
        },
    );
}

/* ------------------------------------------------------------------ */
/* Restore (trashed pages)                                             */
/* ------------------------------------------------------------------ */

const restoring = ref(false);

function restorePage(): void {
    restoring.value = true;

    router.post(
        `/manage/pages/${props.page.id}/restore`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                restoring.value = false;
            },
        },
    );
}

/* ------------------------------------------------------------------ */
/* Create-child dialog (used by the children tab)                      */
/* ------------------------------------------------------------------ */

const createChildOpen = ref(false);

/* ------------------------------------------------------------------ */
/* Dirty guard for explicit-save tabs                                  */
/* ------------------------------------------------------------------ */

const contentTab = useTemplateRef('contentTab');
const settingsTab = useTemplateRef('settingsTab');
const telegramTab = useTemplateRef('telegramTab');

const hasUnsavedChanges = computed(() => Boolean(contentTab.value?.isDirty || settingsTab.value?.isDirty || telegramTab.value?.isDirty));

function handleBeforeUnload(event: BeforeUnloadEvent): void {
    if (hasUnsavedChanges.value) {
        event.preventDefault();
    }
}

let removeBeforeListener: (() => void) | null = null;

onMounted(() => {
    window.addEventListener('beforeunload', handleBeforeUnload);

    removeBeforeListener = router.on('before', (event) => {
        const visit = event.detail.visit;

        if (!hasUnsavedChanges.value || visit.method !== 'get') {
            return;
        }

        if (visit.url.pathname === window.location.pathname) {
            return;
        }

        if (!window.confirm('لديك تغييرات غير محفوظة. هل تريد المغادرة دون حفظ؟')) {
            event.preventDefault();
        }
    });
});

onUnmounted(() => {
    window.removeEventListener('beforeunload', handleBeforeUnload);
    removeBeforeListener?.();
});
</script>

<template>
    <Head :title="page.title" />

    <div class="space-y-4">
        <nav aria-label="مسار الصفحة" class="flex flex-wrap items-center gap-1 text-sm text-muted-foreground">
            <Link href="/manage/pages" class="transition-colors hover:text-foreground">الصفحات</Link>
            <template v-for="ancestor in parentChain" :key="ancestor.id">
                <ChevronLeft class="size-3.5" aria-hidden="true" />
                <Link :href="`/manage/pages/${ancestor.id}/edit`" class="transition-colors hover:text-foreground">{{ ancestor.title }}</Link>
            </template>
            <ChevronLeft class="size-3.5" aria-hidden="true" />
            <span class="text-foreground">{{ displayedTitle }}</span>
        </nav>

        <div
            v-if="page.deleted_at"
            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3"
        >
            <p class="text-sm text-destructive-foreground">هذه الصفحة محذوفة ولا تظهر في الموقع ولا في البوت.</p>
            <Button variant="outline" size="sm" :disabled="restoring" @click="restorePage">
                <Loader2 v-if="restoring" class="size-4 animate-spin" />
                <ArchiveRestore v-else class="size-4" />
                استعادة
            </Button>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0 flex-1 space-y-1">
                <input
                    v-if="editingTitle"
                    ref="titleInput"
                    v-model="titleDraft"
                    type="text"
                    aria-label="عنوان الصفحة"
                    class="w-full max-w-xl rounded-md border border-input bg-transparent px-2 py-1 text-2xl font-bold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @blur="saveTitle"
                    @keydown.enter.prevent="saveTitle"
                    @keydown.esc.prevent="cancelEditingTitle"
                />
                <button
                    v-else
                    type="button"
                    class="group flex max-w-full min-w-0 items-center gap-2 text-start"
                    title="انقر لتعديل العنوان"
                    @click="startEditingTitle"
                >
                    <h1 class="truncate text-2xl font-bold">{{ displayedTitle }}</h1>
                    <Loader2 v-if="savingTitle" class="size-4 shrink-0 animate-spin text-muted-foreground" />
                    <Pencil v-else class="size-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                </button>
                <p v-if="titleError" class="text-sm text-destructive-foreground">{{ titleError }}</p>
            </div>

            <div class="flex items-center gap-2">
                <Badge v-if="page.hidden" variant="secondary">مخفية من الموقع</Badge>
                <Button as-child variant="outline" size="sm">
                    <a :href="page.slug" target="_blank" rel="noopener noreferrer">
                        <ExternalLink class="size-4" />
                        عرض في الموقع
                    </a>
                </Button>
            </div>
        </div>

        <div role="tablist" aria-label="أقسام الصفحة" class="flex w-fit max-w-full flex-wrap gap-1 rounded-lg bg-muted p-1">
            <button
                v-for="tab in tabs"
                :key="tab.name"
                type="button"
                role="tab"
                :aria-selected="activeTab === tab.name"
                class="rounded-md px-4 py-1.5 text-sm font-medium whitespace-nowrap transition-colors"
                :class="activeTab === tab.name ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                @click="setTab(tab.name)"
            >
                {{ tab.label }}
                <span v-if="tab.count" class="text-xs text-muted-foreground">({{ tab.count }})</span>
            </button>
        </div>

        <div v-show="activeTab === 'content'">
            <PageContentTab ref="contentTab" :page="page" :copilot-enabled="copilot.enabled" />
        </div>
        <PageSettingsTab
            v-show="activeTab === 'settings'"
            ref="settingsTab"
            :page="page"
            :parent-options="parentOptions"
            :descendant-ids="descendantIds"
        />
        <div v-show="activeTab === 'telegram'">
            <PageTelegramTab ref="telegramTab" :page="page" :attachments="attachments" :copilot-enabled="copilot.enabled" />
        </div>
        <PageChildrenTab v-show="activeTab === 'children'" :page="page" :children="children" @add-child="createChildOpen = true" />
        <PageAuthorsTab v-show="activeTab === 'authors'" :page="page" :authors="authors" :users="users" />
    </div>

    <PageCreateDialog v-model:open="createChildOpen" :parent-options="parentOptions" :preset-parent-id="page.id" />
</template>
