<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useSortableList } from '@/composables/useSortableList';
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, EllipsisVertical, ExternalLink, GraduationCap, GripVertical, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import TutorFormDialog from './TutorFormDialog.vue';
import type { TutorCourse, TutorRow } from './types';

const props = defineProps<{
    tutors: TutorRow[];
    courses: TutorCourse[];
}>();

const search = ref('');
const isFiltering = computed(() => search.value.trim() !== '');

const reorderError = ref<string | null>(null);

const { items, draggingId, startDrag, dragOver, endDrag, moveUp, moveDown } = useSortableList(
    () => props.tutors,
    (ids) =>
        new Promise<void>((resolve, reject) => {
            router.post(
                '/manage/tutors/reorder',
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

const filteredTutors = computed(() => {
    const query = search.value.trim();

    return query === '' ? items.value : items.value.filter((tutor) => tutor.name.includes(query));
});

function displayUrl(url: string): string {
    return url.replace(/^https?:\/\//, '').replace(/\/$/, '');
}

const formDialogOpen = ref(false);
const editingTutor = ref<TutorRow | null>(null);

function openCreate(): void {
    editingTutor.value = null;
    formDialogOpen.value = true;
}

function openEdit(tutor: TutorRow): void {
    editingTutor.value = tutor;
    formDialogOpen.value = true;
}

defineExpose({ openCreate });

const deletingTutor = ref<TutorRow | null>(null);
const confirmingDeletion = ref(false);
const deleting = ref(false);

function confirmDelete(tutor: TutorRow): void {
    deletingTutor.value = tutor;
    confirmingDeletion.value = true;
}

function deleteTutor(): void {
    if (!deletingTutor.value) {
        return;
    }

    deleting.value = true;

    router.delete(`/manage/tutors/${deletingTutor.value.id}`, {
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
</script>

<template>
    <div class="space-y-4">
        <div v-if="tutors.length" class="flex flex-wrap items-center gap-2">
            <Input v-model="search" type="search" placeholder="ابحث بالاسم…" class="max-w-xs" aria-label="البحث في الخصوصيين" />
            <p v-if="isFiltering" class="text-xs text-muted-foreground">الترتيب بالسحب معطّل أثناء البحث.</p>
        </div>

        <p v-if="reorderError" class="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive-foreground">
            {{ reorderError }}
        </p>

        <EmptyState
            v-if="!tutors.length"
            :icon="GraduationCap"
            title="لا يوجد خصوصيون بعد"
            description="الخصوصيون هم مقدمو الدروس الخصوصية الذين تعرضهم صفحة الخصوصيين في الموقع العام مع مقرراتهم وروابطهم."
        >
            <Button @click="openCreate">
                <Plus />
                إضافة خصوصي
            </Button>
        </EmptyState>

        <p v-else-if="!filteredTutors.length" class="py-8 text-center text-sm text-muted-foreground">لا نتائج مطابقة لبحثك.</p>

        <ul v-else class="overflow-hidden rounded-lg border border-border">
            <li
                v-for="tutor in filteredTutors"
                :key="tutor.id"
                class="flex items-center gap-2 border-b border-border p-3 transition-opacity last:border-b-0"
                :class="{ 'opacity-50': draggingId === tutor.id }"
                :draggable="!isFiltering"
                @dragstart="startDrag(tutor, $event)"
                @dragover="dragOver(tutor, $event)"
                @dragend="endDrag($event)"
                @drop.prevent
            >
                <GripVertical v-if="!isFiltering" class="size-4 shrink-0 cursor-grab text-muted-foreground" aria-hidden="true" />
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="font-medium">{{ tutor.name }}</span>
                        <a
                            v-if="tutor.url"
                            :href="tutor.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            dir="ltr"
                            draggable="false"
                            class="inline-flex max-w-56 items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                        >
                            <ExternalLink class="size-3 shrink-0" />
                            <span class="truncate">{{ displayUrl(tutor.url) }}</span>
                        </a>
                    </div>
                    <div v-if="tutor.courses.length" class="flex flex-wrap gap-1">
                        <Badge v-for="course in tutor.courses" :key="course.id" variant="secondary">{{ course.name }}</Badge>
                    </div>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" size="icon-sm" :aria-label="`إجراءات ${tutor.name}`">
                            <EllipsisVertical />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem @select="openEdit(tutor)">
                            <Pencil />
                            تعديل
                        </DropdownMenuItem>
                        <DropdownMenuItem :disabled="isFiltering" @select="moveUp(tutor)">
                            <ArrowUp />
                            نقل لأعلى
                        </DropdownMenuItem>
                        <DropdownMenuItem :disabled="isFiltering" @select="moveDown(tutor)">
                            <ArrowDown />
                            نقل لأسفل
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem variant="destructive" @select="confirmDelete(tutor)">
                            <Trash2 />
                            حذف
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </li>
        </ul>

        <TutorFormDialog v-model:open="formDialogOpen" :tutor="editingTutor" :courses="courses" />

        <ConfirmDialog
            v-model:open="confirmingDeletion"
            title="حذف الخصوصي"
            destructive
            confirm-label="حذف"
            :processing="deleting"
            @confirm="deleteTutor"
        >
            <template v-if="deletingTutor">
                سيتم حذف الخصوصي «{{ deletingTutor.name }}» نهائياً.
                {{
                    deletingTutor.courses.length > 0
                        ? `سيتم فصل ${deletingTutor.courses.length} من المقررات المرتبطة به دون حذفها.`
                        : 'لا توجد مقررات مرتبطة به.'
                }}
            </template>
        </ConfirmDialog>
    </div>
</template>
