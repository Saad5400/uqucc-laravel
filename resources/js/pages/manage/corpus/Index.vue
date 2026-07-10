<script setup lang="ts">
import type { Paginated } from '@/components/manage/activity/types';
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import CorpusStatusBadge from '@/components/manage/corpus/CorpusStatusBadge.vue';
import CorpusUploadDialog from '@/components/manage/corpus/CorpusUploadDialog.vue';
import { canReingest, extractionStatusLabels, type CorpusDocumentRow, type CorpusFilters } from '@/components/manage/corpus/types';
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import Pagination from '@/components/manage/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatFileSize, formatRelativeTime } from '@/lib/formatters';
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { EllipsisVertical, FileSearch, FileUp, FilterX, Pencil, RefreshCw, Trash2, Upload } from 'lucide-vue-next';
import { computed, onUnmounted, ref, watch } from 'vue';

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    documents: Paginated<CorpusDocumentRow>;
    filters: CorpusFilters;
}>();

/** Extractions run in the background — poll so الحالة moves on its own. */
usePoll(15_000, { only: ['documents'] });

/** Sentinel for "no filter" — reka-ui selects reserve the empty string. */
const ALL = 'all';

const search = ref(props.filters.search ?? '');

const hasActiveFilters = computed(() => Boolean(props.filters.status || props.filters.search));

function visit(filters: CorpusFilters, page?: number): void {
    const query: Record<string, string | number> = {};

    if (filters.status) {
        query.status = filters.status;
    }

    if (filters.search) {
        query.search = filters.search;
    }

    if (page && page > 1) {
        query.page = page;
    }

    router.get('/manage/corpus', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['documents', 'filters'],
    });
}

function applyStatusFilter(value: unknown): void {
    visit({ ...props.filters, status: value === ALL ? null : String(value) });
}

function resetFilters(): void {
    search.value = '';
    visit({ status: null, search: null });
}

function goToPage(page: number): void {
    visit(props.filters, page);
}

let searchDebounce: ReturnType<typeof setTimeout> | null = null;

watch(search, (value) => {
    if (searchDebounce) {
        clearTimeout(searchDebounce);
    }

    searchDebounce = setTimeout(() => visit({ ...props.filters, search: value.trim() || null }), 300);
});

onUnmounted(() => {
    if (searchDebounce) {
        clearTimeout(searchDebounce);
    }
});

const uploading = ref(false);

/* ------------------------------------------------------------------ */
/* Row actions (each behind a ConfirmDialog)                           */
/* ------------------------------------------------------------------ */

type ConfirmableAction = 'reextract' | 'reingest' | 'delete';

const confirmingAction = ref<ConfirmableAction | null>(null);
const targetDocument = ref<CorpusDocumentRow | null>(null);
const processing = ref(false);

function confirmAction(action: ConfirmableAction, document: CorpusDocumentRow): void {
    targetDocument.value = document;
    confirmingAction.value = action;
}

function runConfirmedAction(): void {
    if (!targetDocument.value || !confirmingAction.value) {
        return;
    }

    const id = targetDocument.value.id;
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            confirmingAction.value = null;
        },
        onFinish: () => {
            processing.value = false;
        },
    };

    processing.value = true;

    if (confirmingAction.value === 'delete') {
        router.delete(`/manage/corpus/${id}`, options);
    } else {
        router.post(`/manage/corpus/${id}/${confirmingAction.value}`, {}, options);
    }
}
</script>

<template>
    <Head title="مستندات الذكاء الاصطناعي" />
    <PageHeader title="مستندات الذكاء الاصطناعي" description="ملفات المعرفة (لوائح، أدلة، نماذج) التي يبحث فيها الذكاء الاصطناعي">
        <template #actions>
            <Button @click="uploading = true">
                <Upload />
                رفع مستند
            </Button>
        </template>
    </PageHeader>

    <div class="space-y-4">
        <div v-if="documents.data.length || hasActiveFilters" class="flex flex-wrap items-center gap-2">
            <Input v-model="search" type="search" placeholder="ابحث بالعنوان…" class="max-w-xs" aria-label="البحث في المستندات" />

            <Select :model-value="filters.status ?? ALL" @update:model-value="applyStatusFilter">
                <SelectTrigger class="w-44" aria-label="تصفية بالحالة">
                    <SelectValue placeholder="الحالة" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">كل الحالات</SelectItem>
                    <SelectItem v-for="(label, status) in extractionStatusLabels" :key="status" :value="status">{{ label }}</SelectItem>
                </SelectContent>
            </Select>

            <Button v-if="hasActiveFilters" variant="ghost" size="sm" class="text-muted-foreground" @click="resetFilters">
                <FilterX class="size-4" />
                إعادة التعيين
            </Button>
        </div>

        <EmptyState
            v-if="!documents.data.length && !hasActiveFilters"
            :icon="FileUp"
            title="لا توجد مستندات بعد"
            description="ارفع لوائح وأدلة PDF أو صوراً ليستخرج النظام نصوصها ويفهرسها في البحث الذكي والمساعد."
        >
            <Button @click="uploading = true">
                <Upload />
                رفع مستند
            </Button>
        </EmptyState>

        <p v-else-if="!documents.data.length" class="py-8 text-center text-sm text-muted-foreground">لا نتائج مطابقة للتصفية الحالية.</p>

        <ul v-else class="overflow-hidden rounded-lg border border-border">
            <li v-for="document in documents.data" :key="document.id" class="flex items-center gap-3 border-b border-border p-3 last:border-b-0">
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <Link :href="`/manage/corpus/${document.id}/edit`" class="font-medium hover:underline">{{ document.title }}</Link>
                        <Badge variant="secondary">{{ document.is_pdf ? 'PDF' : 'صورة' }}</Badge>
                        <CorpusStatusBadge kind="extraction" :status="document.status" :error="document.error" />
                        <CorpusStatusBadge kind="index" :status="document.index_status" />
                    </div>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                        <span dir="ltr" class="truncate">{{ document.original_filename }}</span>
                        <span v-if="document.size !== null" dir="ltr" class="tabular-nums">{{ formatFileSize(document.size) }}</span>
                        <span v-if="document.uploader_name">رفعه {{ document.uploader_name }}</span>
                        <span v-if="document.created_at">{{ formatRelativeTime(document.created_at) }}</span>
                    </div>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" size="icon-sm" :aria-label="`إجراءات ${document.title}`">
                            <EllipsisVertical />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem as-child>
                            <Link :href="`/manage/corpus/${document.id}/edit`">
                                <Pencil />
                                تعديل
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="confirmAction('reextract', document)">
                            <RefreshCw />
                            إعادة الاستخراج
                        </DropdownMenuItem>
                        <DropdownMenuItem v-if="canReingest(document)" @select="confirmAction('reingest', document)">
                            <FileSearch />
                            إعادة الفهرسة
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem variant="destructive" @select="confirmAction('delete', document)">
                            <Trash2 />
                            حذف
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </li>
        </ul>

        <Pagination :page="documents.current_page" :pages="documents.last_page" :total="documents.total" @update:page="goToPage" />

        <CorpusUploadDialog v-model:open="uploading" />

        <ConfirmDialog
            :open="confirmingAction === 'reextract'"
            title="إعادة استخراج النص"
            confirm-label="إعادة الاستخراج"
            :processing="processing"
            @confirm="runConfirmedAction"
            @update:open="(value) => (confirmingAction = value ? 'reextract' : null)"
        >
            سيُعاد استخراج النص من الملف (عبر طبقة النص أو نموذج الرؤية) ثم تُعاد فهرسته. يستبدل هذا أي تعديلات يدوية على النص المستخرج.
        </ConfirmDialog>

        <ConfirmDialog
            :open="confirmingAction === 'reingest'"
            title="إعادة الفهرسة"
            confirm-label="إعادة الفهرسة"
            :processing="processing"
            @confirm="runConfirmedAction"
            @update:open="(value) => (confirmingAction = value ? 'reingest' : null)"
        >
            ستُعاد تجزئة النص المستخرج وتضمينه في فهرس البحث الذكي دون إعادة الاستخراج.
        </ConfirmDialog>

        <ConfirmDialog
            :open="confirmingAction === 'delete'"
            title="حذف المستند"
            destructive
            confirm-label="حذف"
            :processing="processing"
            @confirm="runConfirmedAction"
            @update:open="(value) => (confirmingAction = value ? 'delete' : null)"
        >
            <template v-if="targetDocument"> سيُحذف المستند «{{ targetDocument.title }}» وملفه المخزن وكل مقاطعه من فهرس البحث الذكي. </template>
        </ConfirmDialog>
    </div>
</template>
