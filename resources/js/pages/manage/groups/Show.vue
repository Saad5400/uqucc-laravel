<script setup lang="ts">
import EmptyState from '@/components/manage/EmptyState.vue';
import CohortFormDialog from '@/components/manage/groups/CohortFormDialog.vue';
import GroupCard from '@/components/manage/groups/GroupCard.vue';
import GroupFormDialog from '@/components/manage/groups/GroupFormDialog.vue';
import type { CohortRow, GroupRow, Taxonomy } from '@/components/manage/groups/types';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useSortableList } from '@/composables/useSortableList';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, CheckCircle2, ExternalLink, EyeOff, MessagesSquare, Pencil, Plus, Star, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    cohort: CohortRow;
    groups: GroupRow[];
    taxonomy: Taxonomy;
}>();

const isUnreachable = computed(() => props.cohort.is_active && props.cohort.available_supervisors_count === 0);

const reorderError = ref<string | null>(null);

const { items, draggingId, startDrag, dragOver, endDrag, moveUp, moveDown } = useSortableList<GroupRow>(
    () => props.groups,
    (ids) =>
        new Promise<void>((resolve, reject) => {
            router.post(
                `/manage/cohorts/${props.cohort.id}/groups/reorder`,
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

/** One open group at a time keeps a dozen groups readable on one screen. */
const expandedGroupId = ref<number | null>(null);

function toggleGroup(group: GroupRow): void {
    expandedGroupId.value = expandedGroupId.value === group.id ? null : group.id;
}

const cohortDialogOpen = ref(false);

const groupDialogOpen = ref(false);
const editingGroup = ref<GroupRow | null>(null);

function openCreateGroup(): void {
    editingGroup.value = null;
    groupDialogOpen.value = true;
}

function openEditGroup(group: GroupRow): void {
    editingGroup.value = group;
    groupDialogOpen.value = true;
}

function deleteGroup(group: GroupRow): void {
    // Scoped to this intake: the server detaches a shared group instead of deleting it.
    router.delete(`/manage/cohorts/${props.cohort.id}/groups/${group.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="cohort.name" />

    <div class="mb-4">
        <Link href="/manage/cohorts" class="inline-flex items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground">
            <ArrowRight class="size-4" />
            كل الدفعات
        </Link>
    </div>

    <PageHeader :title="cohort.name" :description="cohort.description ?? 'لا يوجد تعريف لهذه الدفعة بعد.'">
        <template #actions>
            <Button as-child variant="outline">
                <a href="/qroubat" target="_blank" rel="noopener noreferrer">
                    <ExternalLink />
                    معاينة الصفحة
                </a>
            </Button>
            <Button variant="outline" @click="cohortDialogOpen = true">
                <Pencil />
                تعديل بيانات الدفعة
            </Button>
        </template>
    </PageHeader>

    <div class="mb-6 space-y-4 rounded-lg border border-border p-4">
        <div class="flex flex-wrap items-center gap-2">
            <Badge :variant="cohort.is_active ? 'default' : 'secondary'">
                {{ cohort.is_active ? 'معروضة في الموقع' : 'مخفية عن الزوار' }}
            </Badge>
            <Badge v-if="cohort.is_featured" variant="default">
                <Star aria-hidden="true" />
                الدفعة المستجدة
            </Badge>
            <Badge v-if="!cohort.shows_major_groups" variant="outline">
                <EyeOff aria-hidden="true" />
                بدون قروبات التخصصات
            </Badge>
            <Badge v-if="isUnreachable" variant="destructive">
                <TriangleAlert aria-hidden="true" />
                لا يوجد مشرف متاح
            </Badge>
        </div>

        <p v-if="!cohort.shows_major_groups" class="text-sm text-muted-foreground">
            خطوة قروب التخصص مخفية في صفحة القروبات، فينضم الطالب للقروب العام وحده. القروبات أدناه ومشرفوها محفوظة كما هي، وتعود للظهور بتفعيل «عرض
            قروبات التخصصات» من «تعديل بيانات الدفعة».
        </p>

        <p v-if="isUnreachable" class="text-sm text-muted-foreground">
            الدفعة معروضة لكن لا يوجد فيها مشرف متاح، فلن يجد الزائر من يراسله. فعّل أحد المشرفين أو أضف مشرفاً جديداً.
        </p>

        <div>
            <h2 class="mb-2 text-sm font-medium">شروط الانضمام</h2>
            <ul v-if="cohort.requirements.length" class="m-0 list-none space-y-1.5 p-0">
                <li v-for="(requirement, index) in cohort.requirements" :key="index" class="flex items-start gap-2 text-sm">
                    <CheckCircle2 class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                    <span>{{ requirement }}</span>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">لا توجد شروط. أضفها من «تعديل بيانات الدفعة» لتظهر للزائر كقائمة قبل مراسلة المشرف.</p>
        </div>

        <p v-if="cohort.note" class="flex items-start gap-2 rounded-lg border border-border bg-muted/40 p-3 text-sm text-muted-foreground">
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ cohort.note }}</span>
        </p>
    </div>

    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-lg font-semibold">القروبات</h2>
        <Button variant="outline" size="sm" @click="openCreateGroup">
            <Plus />
            إضافة قروب
        </Button>
    </div>

    <p v-if="reorderError" class="mb-3 rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive-foreground">
        {{ reorderError }}
    </p>

    <EmptyState
        v-if="!items.length"
        :icon="MessagesSquare"
        title="لا توجد قروبات في هذه الدفعة"
        description="القروب يُعرَّف بتخصصه وفرعه. ابدأ بالقروب العام للدفعة، ثم أضف قروب كل تخصص في كل فرع."
    >
        <Button @click="openCreateGroup">
            <Plus />
            إضافة قروب
        </Button>
    </EmptyState>

    <ul v-else class="m-0 list-none space-y-2 p-0">
        <GroupCard
            v-for="group in items"
            :key="group.id"
            :group="group"
            :taxonomy="taxonomy"
            :expanded="expandedGroupId === group.id"
            :dragging="draggingId === group.id"
            draggable="true"
            @dragstart="startDrag(group, $event)"
            @dragover="dragOver(group, $event)"
            @dragend="endDrag($event)"
            @drop.prevent
            @toggle="toggleGroup(group)"
            @edit="openEditGroup(group)"
            @move-up="moveUp(group)"
            @move-down="moveDown(group)"
            @delete="deleteGroup(group)"
        />
    </ul>

    <CohortFormDialog v-model:open="cohortDialogOpen" :cohort="cohort" />
    <GroupFormDialog v-model:open="groupDialogOpen" :cohort-id="cohort.id" :taxonomy="taxonomy" :group="editingGroup" />
</template>
