<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import CohortFormDialog from '@/components/manage/groups/CohortFormDialog.vue';
import type { CohortRow } from '@/components/manage/groups/types';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import { useSortableList } from '@/composables/useSortableList';
import { arabicSupervisors } from '@/lib/arabic';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ChevronLeft,
    EllipsisVertical,
    EyeOff,
    GripVertical,
    MessagesSquare,
    Pencil,
    Plus,
    Star,
    Trash2,
    TriangleAlert,
} from 'lucide-vue-next';
import { ref } from 'vue';

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    cohorts: CohortRow[];
}>();

const reorderError = ref<string | null>(null);

const { items, draggingId, startDrag, dragOver, endDrag, moveUp, moveDown } = useSortableList<CohortRow>(
    () => props.cohorts,
    (ids) =>
        new Promise<void>((resolve, reject) => {
            router.post(
                '/manage/cohorts/reorder',
                { ids },
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

/** Optimistic overrides for the visibility switch — cheap and reversible. */
const pendingVisibility = ref<Record<number, boolean>>({});
const visibilityError = ref<string | null>(null);

function isActive(cohort: CohortRow): boolean {
    return pendingVisibility.value[cohort.id] ?? cohort.is_active;
}

function toggleVisibility(cohort: CohortRow, value: boolean): void {
    pendingVisibility.value = { ...pendingVisibility.value, [cohort.id]: value };
    visibilityError.value = null;

    router.put(
        `/manage/cohorts/${cohort.id}`,
        { is_active: value },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                visibilityError.value = `تعذر تحديث حالة «${cohort.name}».`;
            },
            onFinish: () => {
                const next = { ...pendingVisibility.value };
                delete next[cohort.id];
                pendingVisibility.value = next;
            },
        },
    );
}

const formDialogOpen = ref(false);
const editingCohort = ref<CohortRow | null>(null);

function openCreate(): void {
    editingCohort.value = null;
    formDialogOpen.value = true;
}

function openEdit(cohort: CohortRow): void {
    editingCohort.value = cohort;
    formDialogOpen.value = true;
}

const deletingCohort = ref<CohortRow | null>(null);
const confirmingDeletion = ref(false);
const deleting = ref(false);

function confirmDelete(cohort: CohortRow): void {
    deletingCohort.value = cohort;
    confirmingDeletion.value = true;
}

function deleteCohort(): void {
    if (!deletingCohort.value) {
        return;
    }

    deleting.value = true;

    router.delete(`/manage/cohorts/${deletingCohort.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            confirmingDeletion.value = false;
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}
</script>

<template>
    <Head title="قروبات الدفعات" />
    <PageHeader title="قروبات الدفعات" description="دفعات الكلية وقروبات تخصصاتها ومشرفوها، كما تظهر في صفحة القروبات">
        <template #actions>
            <Button @click="openCreate">
                <Plus />
                إضافة دفعة
            </Button>
        </template>
    </PageHeader>

    <p v-if="reorderError" class="mb-3 rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive-foreground">
        {{ reorderError }}
    </p>
    <p v-if="visibilityError" class="mb-3 rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive-foreground">
        {{ visibilityError }}
    </p>

    <EmptyState
        v-if="!items.length"
        :icon="MessagesSquare"
        title="لا توجد دفعات بعد"
        description="الدفعة تجمع قروبات التخصصات وشروط الانضمام المشتركة بينها. أضف دفعة ثم أضف قروباتها ومشرفيها، وستوزّع الصفحة العامة طلبات الانضمام عليهم بالتساوي."
    >
        <Button @click="openCreate">
            <Plus />
            إضافة دفعة
        </Button>
    </EmptyState>

    <ul v-else class="overflow-hidden rounded-lg border border-border">
        <li
            v-for="cohort in items"
            :key="cohort.id"
            class="flex items-center gap-2 border-b border-border p-3 transition-opacity last:border-b-0"
            :class="{ 'opacity-50': draggingId === cohort.id }"
            draggable="true"
            @dragstart="startDrag(cohort, $event)"
            @dragover="dragOver(cohort, $event)"
            @dragend="endDrag($event)"
            @drop.prevent
        >
            <span class="-m-2 flex size-10 shrink-0 cursor-grab items-center justify-center" aria-hidden="true">
                <GripVertical class="size-4 text-muted-foreground" />
            </span>

            <Link :href="`/manage/cohorts/${cohort.id}`" class="min-w-0 flex-1 space-y-1 rounded-md" draggable="false">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="font-medium">{{ cohort.name }}</span>
                    <Badge v-if="cohort.is_featured" variant="default">
                        <Star aria-hidden="true" />
                        المستجدة
                    </Badge>
                    <Badge v-if="!isActive(cohort)" variant="secondary">مخفية</Badge>
                    <Badge v-else-if="!cohort.available_supervisors_count" variant="destructive">
                        <TriangleAlert aria-hidden="true" />
                        بلا مشرف متاح
                    </Badge>
                    <Badge v-if="!cohort.shows_major_groups" variant="outline" title="خطوة قروب التخصص مخفية عن الزوار في هذه الدفعة">
                        <EyeOff aria-hidden="true" />
                        بدون قروبات التخصصات
                    </Badge>
                </div>
                <p class="text-xs text-muted-foreground tabular-nums">
                    {{ cohort.groups_count }} قروب · {{ cohort.available_supervisors_count }} متاح من
                    {{ arabicSupervisors(cohort.supervisors_count) }}
                </p>
            </Link>

            <Switch
                :model-value="isActive(cohort)"
                :aria-label="`عرض ${cohort.name} في الموقع`"
                :title="isActive(cohort) ? 'معروضة في الموقع العام' : 'مخفية عن الزوار'"
                @update:model-value="toggleVisibility(cohort, $event)"
            />

            <Button as-child variant="ghost" size="icon" :aria-label="`فتح ${cohort.name}`">
                <Link :href="`/manage/cohorts/${cohort.id}`" draggable="false">
                    <ChevronLeft />
                </Link>
            </Button>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button variant="ghost" size="icon" :aria-label="`إجراءات ${cohort.name}`">
                        <EllipsisVertical />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem @select="openEdit(cohort)">
                        <Pencil />
                        تعديل
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="moveUp(cohort)">
                        <ArrowUp />
                        نقل لأعلى
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="moveDown(cohort)">
                        <ArrowDown />
                        نقل لأسفل
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" @select="confirmDelete(cohort)">
                        <Trash2 />
                        حذف
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </li>
    </ul>

    <CohortFormDialog v-model:open="formDialogOpen" :cohort="editingCohort" />

    <ConfirmDialog
        v-model:open="confirmingDeletion"
        title="حذف الدفعة"
        destructive
        confirm-label="حذف"
        :processing="deleting"
        @confirm="deleteCohort"
    >
        <template v-if="deletingCohort">
            سيتم حذف دفعة «{{ deletingCohort.name }}» و{{ deletingCohort.groups_count }} من قروباتها و{{
                arabicSupervisors(deletingCohort.supervisors_count)
            }}
            فيها نهائياً، ولا يمكن التراجع. إن كان الغرض إخفاءها عن الزوار فقط، أوقف مفتاح العرض بدلاً من الحذف.
        </template>
    </ConfirmDialog>
</template>
