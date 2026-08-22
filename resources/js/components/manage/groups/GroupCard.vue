<script setup lang="ts">
/**
 * One group inside the intake workspace, collapsed to a single row until it is
 * opened. An intake carries a dozen or more groups with a hundred-odd
 * supervisors between them — expanded by default, the page would be unusable.
 */
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import { arabicSupervisors } from '@/lib/arabic';
import { router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ChevronDown,
    EllipsisVertical,
    GripVertical,
    Pencil,
    Plus,
    Share2,
    Trash2,
    TriangleAlert,
    UserRound,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import SupervisorFormDialog from './SupervisorFormDialog.vue';
import SupervisorList from './SupervisorList.vue';
import type { GroupRow, SupervisorRow, Taxonomy } from './types';

const props = defineProps<{
    group: GroupRow;
    taxonomy: Taxonomy;
    expanded: boolean;
    dragging: boolean;
}>();

const emit = defineEmits<{
    toggle: [];
    edit: [];
    moveUp: [];
    moveDown: [];
    delete: [];
}>();

const availableCount = computed(() => props.group.supervisors.filter((supervisor) => supervisor.is_available).length);

/** Only the sections that actually have someone; the dialog is how a new one starts. */
const filledSections = computed(() =>
    props.taxonomy.sections
        .map((section) => ({
            section,
            supervisors: props.group.supervisors.filter((supervisor) => supervisor.section === section.value),
        }))
        .filter((bucket) => bucket.supervisors.length > 0),
);

/** A visible group nobody can be routed to is broken in public. */
const isUnreachable = computed(() => props.group.is_active && availableCount.value === 0);

const pendingVisibility = ref<boolean | null>(null);
const visibilityError = ref<string | null>(null);

const isActive = computed(() => pendingVisibility.value ?? props.group.is_active);

function toggleVisibility(value: boolean): void {
    pendingVisibility.value = value;
    visibilityError.value = null;

    router.put(
        `/manage/groups/${props.group.id}`,
        { is_active: value },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                visibilityError.value = 'تعذر تحديث حالة القروب.';
            },
            onFinish: () => {
                pendingVisibility.value = null;
            },
        },
    );
}

const formDialogOpen = ref(false);
const editingSupervisor = ref<SupervisorRow | null>(null);
const defaultSection = ref(props.taxonomy.sections[0]?.value ?? 'men');

function openCreate(sectionKey?: string): void {
    editingSupervisor.value = null;
    defaultSection.value = sectionKey ?? props.taxonomy.sections[0]?.value ?? 'men';
    formDialogOpen.value = true;
}

function openEdit(supervisor: SupervisorRow): void {
    editingSupervisor.value = supervisor;
    formDialogOpen.value = true;
}

const confirmingDeletion = ref(false);
</script>

<template>
    <li class="overflow-hidden rounded-lg border border-border transition-opacity" :class="{ 'opacity-50': dragging }">
        <div class="flex items-center gap-2 p-3">
            <span class="-m-2 flex size-10 shrink-0 cursor-grab items-center justify-center" aria-hidden="true">
                <GripVertical class="size-4 text-muted-foreground" />
            </span>

            <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-start" :aria-expanded="expanded" @click="emit('toggle')">
                <ChevronDown
                    class="size-4 shrink-0 text-muted-foreground transition-transform"
                    :class="expanded ? 'rotate-180' : ''"
                    aria-hidden="true"
                />
                <span class="min-w-0 flex-1 space-y-0.5">
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-medium">{{ group.name }}</span>
                        <Badge v-if="group.is_general" variant="secondary">عام</Badge>
                        <Badge v-for="shared in group.shared_with" :key="shared.id" variant="outline">
                            <Share2 aria-hidden="true" />
                            مشترك مع {{ shared.name }}
                        </Badge>
                        <Badge v-if="!isActive" variant="secondary">مخفي</Badge>
                        <Badge v-else-if="isUnreachable" variant="destructive">
                            <TriangleAlert aria-hidden="true" />
                            بلا مشرف متاح
                        </Badge>
                    </span>
                    <span class="block text-xs text-muted-foreground">
                        {{ group.branch_label }} ·
                        <span class="tabular-nums">{{ availableCount }} متاح من {{ arabicSupervisors(group.supervisors.length) }}</span>
                    </span>
                </span>
            </button>

            <Switch
                :model-value="isActive"
                :aria-label="`عرض ${group.name} في الموقع`"
                :title="isActive ? 'معروض في الموقع العام' : 'مخفي عن الزوار'"
                @update:model-value="toggleVisibility($event)"
            />

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button variant="ghost" size="icon" :aria-label="`إجراءات ${group.name}`">
                        <EllipsisVertical />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem @select="emit('edit')">
                        <Pencil />
                        تعديل القروب
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="openCreate()">
                        <Plus />
                        إضافة مشرف
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="emit('moveUp')">
                        <ArrowUp />
                        نقل لأعلى
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="emit('moveDown')">
                        <ArrowDown />
                        نقل لأسفل
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" @select="confirmingDeletion = true">
                        <Trash2 />
                        {{ group.shared_with.length ? 'إزالة من هذه الدفعة' : 'حذف القروب' }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <div v-if="expanded" class="space-y-4 border-t border-border bg-muted/20 p-3">
            <p v-if="visibilityError" class="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive-foreground">
                {{ visibilityError }}
            </p>

            <EmptyState
                v-if="!filledSections.length"
                :icon="UserRound"
                title="لا يوجد مشرفون في هذا القروب"
                description="المشرف هو من يراسله الطالب لينضم. أضف مشرفاً واحداً على الأقل ليظهر القروب في الموقع العام."
            >
                <Button @click="openCreate()">
                    <Plus />
                    إضافة مشرف
                </Button>
            </EmptyState>

            <SupervisorList
                v-for="bucket in filledSections"
                :key="bucket.section.value"
                :group-id="group.id"
                :section="bucket.section"
                :supervisors="bucket.supervisors"
                @create="openCreate"
                @edit="openEdit"
            />
        </div>

        <SupervisorFormDialog
            v-model:open="formDialogOpen"
            :group-id="group.id"
            :taxonomy="taxonomy"
            :supervisor="editingSupervisor"
            :default-section="defaultSection"
        />

        <ConfirmDialog
            v-model:open="confirmingDeletion"
            :title="group.shared_with.length ? 'إزالة القروب من هذه الدفعة' : 'حذف القروب'"
            destructive
            :confirm-label="group.shared_with.length ? 'إزالة' : 'حذف'"
            @confirm="
                confirmingDeletion = false;
                emit('delete');
            "
        >
            <template v-if="group.shared_with.length">
                سيُزال قروب «{{ group.name }}» من هذه الدفعة فقط، ويبقى كما هو في
                {{ group.shared_with.map((shared) => shared.name).join('، ') }} بمشرفيه.
            </template>
            <template v-else>
                سيتم حذف قروب «{{ group.name }}»
                {{ group.supervisors.length ? `و${arabicSupervisors(group.supervisors.length)} فيه` : 'ولا يوجد مشرفون فيه' }}
                نهائياً. إن كان الغرض إخفاءه عن الزوار فقط، أوقف مفتاح العرض بدلاً من الحذف.
            </template>
        </ConfirmDialog>
    </li>
</template>
