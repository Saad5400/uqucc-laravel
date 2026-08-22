<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import { useSortableList } from '@/composables/useSortableList';
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, EllipsisVertical, GripVertical, MessageCircle, Pencil, Plus, Send, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { SupervisorRow, TaxonomyOption } from './types';

const props = defineProps<{
    groupId: number;
    section: TaxonomyOption;
    supervisors: SupervisorRow[];
}>();

const emit = defineEmits<{
    create: [sectionKey: string];
    edit: [supervisor: SupervisorRow];
}>();

const reorderError = ref<string | null>(null);

const { items, draggingId, startDrag, dragOver, endDrag, moveUp, moveDown } = useSortableList<SupervisorRow>(
    () => props.supervisors,
    (ids) =>
        new Promise<void>((resolve, reject) => {
            router.post(
                `/manage/groups/${props.groupId}/supervisors/reorder`,
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

const availableCount = computed(() => items.value.filter((supervisor) => isAvailable(supervisor)).length);

/**
 * Optimistic overrides for the availability switch: a cheap, reversible write,
 * so the switch moves under the finger and rolls back with an inline error if
 * the server refuses.
 */
const pendingAvailability = ref<Record<number, boolean>>({});
const availabilityError = ref<string | null>(null);

function isAvailable(supervisor: SupervisorRow): boolean {
    return pendingAvailability.value[supervisor.id] ?? supervisor.is_available;
}

function toggleAvailability(supervisor: SupervisorRow, value: boolean): void {
    pendingAvailability.value = { ...pendingAvailability.value, [supervisor.id]: value };
    availabilityError.value = null;

    router.put(
        `/manage/supervisors/${supervisor.id}`,
        { is_available: value },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                availabilityError.value = `تعذر تحديث حالة «${supervisor.name}».`;
            },
            onFinish: () => {
                const next = { ...pendingAvailability.value };
                delete next[supervisor.id];
                pendingAvailability.value = next;
            },
        },
    );
}

const deletingSupervisor = ref<SupervisorRow | null>(null);
const confirmingDeletion = ref(false);
const deleting = ref(false);

function confirmDelete(supervisor: SupervisorRow): void {
    deletingSupervisor.value = supervisor;
    confirmingDeletion.value = true;
}

function deleteSupervisor(): void {
    if (!deletingSupervisor.value) {
        return;
    }

    deleting.value = true;

    router.delete(`/manage/supervisors/${deletingSupervisor.value.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            confirmingDeletion.value = false;
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}

const iconFor = (kind: string) => (kind === 'whatsapp' ? MessageCircle : Send);
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <h4 class="text-sm font-medium">{{ section.label }}</h4>
                <span class="text-xs text-muted-foreground tabular-nums">{{ availableCount }} متاح من {{ items.length }}</span>
            </div>
            <Button variant="ghost" size="sm" @click="emit('create', section.value)">
                <Plus />
                إضافة مشرف
            </Button>
        </div>

        <p v-if="reorderError" class="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive-foreground">
            {{ reorderError }}
        </p>
        <p v-if="availabilityError" class="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive-foreground">
            {{ availabilityError }}
        </p>

        <ul class="overflow-hidden rounded-lg border border-border">
            <li
                v-for="supervisor in items"
                :key="supervisor.id"
                class="flex items-center gap-2 border-b border-border p-2 transition-opacity last:border-b-0"
                :class="{ 'opacity-50': draggingId === supervisor.id }"
                draggable="true"
                @dragstart="startDrag(supervisor, $event)"
                @dragover="dragOver(supervisor, $event)"
                @dragend="endDrag($event)"
                @drop.prevent
            >
                <span class="-m-1 flex size-9 shrink-0 cursor-grab items-center justify-center" aria-hidden="true">
                    <GripVertical class="size-4 text-muted-foreground" />
                </span>

                <div class="min-w-0 flex-1 space-y-0.5">
                    <p class="truncate text-sm font-medium" :class="isAvailable(supervisor) ? '' : 'text-muted-foreground'">
                        {{ supervisor.name }}
                    </p>
                    <span class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                        <a
                            v-for="contact in supervisor.contacts"
                            :key="contact.kind"
                            :href="contact.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            draggable="false"
                            class="inline-flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                        >
                            <component :is="iconFor(contact.kind)" class="size-3 shrink-0" />
                            <bdi dir="ltr">{{ contact.handle }}</bdi>
                        </a>
                    </span>
                </div>

                <Switch
                    :model-value="isAvailable(supervisor)"
                    :aria-label="`توفر ${supervisor.name}`"
                    :title="isAvailable(supervisor) ? 'متاح — يظهر في التوزيع' : 'موقوف — لا يظهر للزوار'"
                    @update:model-value="toggleAvailability(supervisor, $event)"
                />

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" size="icon" :aria-label="`إجراءات ${supervisor.name}`">
                            <EllipsisVertical />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem @select="emit('edit', supervisor)">
                            <Pencil />
                            تعديل
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="moveUp(supervisor)">
                            <ArrowUp />
                            نقل لأعلى
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="moveDown(supervisor)">
                            <ArrowDown />
                            نقل لأسفل
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem variant="destructive" @select="confirmDelete(supervisor)">
                            <Trash2 />
                            حذف
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </li>
        </ul>

        <ConfirmDialog
            v-model:open="confirmingDeletion"
            title="حذف المشرف"
            destructive
            confirm-label="حذف"
            :processing="deleting"
            @confirm="deleteSupervisor"
        >
            <template v-if="deletingSupervisor">
                سيتم حذف «{{ deletingSupervisor.name }}» من هذا القروب نهائياً ولن يظهر في التوزيع. إن كان الغرض إيقافه مؤقتاً، استخدم مفتاح التوفر
                بدلاً من الحذف.
            </template>
        </ConfirmDialog>
    </div>
</template>
