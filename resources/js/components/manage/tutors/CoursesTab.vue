<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useSortableList } from '@/composables/useSortableList';
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, BookOpen, EllipsisVertical, GripVertical, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import CourseFormDialog from './CourseFormDialog.vue';
import { isolateLatinTokens, type CourseRow } from './types';

const props = defineProps<{
    courses: CourseRow[];
}>();

const search = ref('');
const isFiltering = computed(() => search.value.trim() !== '');

const reorderError = ref<string | null>(null);

const { items, draggingId, startDrag, dragOver, endDrag, moveUp, moveDown } = useSortableList(
    () => props.courses,
    (ids) =>
        new Promise<void>((resolve, reject) => {
            router.post(
                '/manage/courses/reorder',
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

const filteredCourses = computed(() => {
    const query = search.value.trim();

    return query === '' ? items.value : items.value.filter((course) => course.name.includes(query));
});

const formDialogOpen = ref(false);
const editingCourse = ref<CourseRow | null>(null);

function openCreate(): void {
    editingCourse.value = null;
    formDialogOpen.value = true;
}

function openEdit(course: CourseRow): void {
    editingCourse.value = course;
    formDialogOpen.value = true;
}

defineExpose({ openCreate });

const deletingCourse = ref<CourseRow | null>(null);
const confirmingDeletion = ref(false);
const deleting = ref(false);

function confirmDelete(course: CourseRow): void {
    deletingCourse.value = course;
    confirmingDeletion.value = true;
}

function deleteCourse(): void {
    if (!deletingCourse.value) {
        return;
    }

    deleting.value = true;

    router.delete(`/manage/courses/${deletingCourse.value.id}`, {
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
        <div v-if="courses.length" class="flex flex-wrap items-center gap-2">
            <Input v-model="search" type="search" placeholder="ابحث بالاسم…" class="max-w-xs" aria-label="البحث في المقررات" />
            <p v-if="isFiltering" class="text-xs text-muted-foreground">الترتيب بالسحب معطّل أثناء البحث.</p>
        </div>

        <p v-if="reorderError" class="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive-foreground">
            {{ reorderError }}
        </p>

        <EmptyState
            v-if="!courses.length"
            :icon="BookOpen"
            title="لا توجد مقررات بعد"
            description="المقررات تُربط بالخصوصيين لتظهر بجانب أسمائهم في صفحة الخصوصيين بالموقع العام."
        >
            <Button @click="openCreate">
                <Plus />
                إضافة مقرر
            </Button>
        </EmptyState>

        <p v-else-if="!filteredCourses.length" class="py-8 text-center text-sm text-muted-foreground">لا نتائج مطابقة لبحثك.</p>

        <ul v-else class="overflow-hidden rounded-lg border border-border">
            <li
                v-for="course in filteredCourses"
                :key="course.id"
                class="flex items-center gap-2 border-b border-border p-3 transition-opacity last:border-b-0"
                :class="{ 'opacity-50': draggingId === course.id }"
                :draggable="!isFiltering"
                @dragstart="startDrag(course, $event)"
                @dragover="dragOver(course, $event)"
                @dragend="endDrag($event)"
                @drop.prevent
            >
                <span v-if="!isFiltering" class="-m-2 flex size-10 shrink-0 cursor-grab items-center justify-center" aria-hidden="true">
                    <GripVertical class="size-4 text-muted-foreground" />
                </span>
                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-medium"
                        ><bdi>{{ isolateLatinTokens(course.name) }}</bdi></span
                    >
                    <Badge variant="outline">
                        {{ course.tutors_count > 0 ? `${course.tutors_count} من الخصوصيين` : 'غير مرتبط' }}
                    </Badge>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" size="icon" :aria-label="`إجراءات ${course.name}`">
                            <EllipsisVertical />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem @select="openEdit(course)">
                            <Pencil />
                            تعديل
                        </DropdownMenuItem>
                        <DropdownMenuItem :disabled="isFiltering" @select="moveUp(course)">
                            <ArrowUp />
                            نقل لأعلى
                        </DropdownMenuItem>
                        <DropdownMenuItem :disabled="isFiltering" @select="moveDown(course)">
                            <ArrowDown />
                            نقل لأسفل
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem variant="destructive" @select="confirmDelete(course)">
                            <Trash2 />
                            حذف
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </li>
        </ul>

        <CourseFormDialog v-model:open="formDialogOpen" :course="editingCourse" />

        <ConfirmDialog
            v-model:open="confirmingDeletion"
            title="حذف المقرر"
            destructive
            confirm-label="حذف"
            :processing="deleting"
            @confirm="deleteCourse"
        >
            <template v-if="deletingCourse">
                سيتم حذف المقرر «<bdi>{{ isolateLatinTokens(deletingCourse.name) }}</bdi
                >» نهائياً.
                {{
                    deletingCourse.tutors_count > 0
                        ? `المقرر مرتبط بـ ${deletingCourse.tutors_count} من الخصوصيين وسيتم فصله عنهم دون حذفهم.`
                        : 'المقرر غير مرتبط بأي خصوصي.'
                }}
            </template>
        </ConfirmDialog>
    </div>
</template>
