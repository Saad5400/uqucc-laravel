<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/vue3';
import { Check, Loader2, Plus, X } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import type { TutorCourse } from './types';

const props = defineProps<{
    courses: TutorCourse[];
}>();

const model = defineModel<number[]>({ required: true });

const search = ref('');
const creating = ref(false);
const createError = ref<string | null>(null);

const normalizedSearch = computed(() => search.value.trim());

const filteredCourses = computed(() =>
    normalizedSearch.value === '' ? props.courses : props.courses.filter((course) => course.name.includes(normalizedSearch.value)),
);

const hasExactMatch = computed(() => props.courses.some((course) => course.name === normalizedSearch.value));

const selectedCourses = computed(() =>
    model.value.map((id) => props.courses.find((course) => course.id === id)).filter((course): course is TutorCourse => course !== undefined),
);

function isSelected(id: number): boolean {
    return model.value.includes(id);
}

function toggleCourse(id: number): void {
    model.value = isSelected(id) ? model.value.filter((selectedId) => selectedId !== id) : [...model.value, id];
}

/**
 * Inline course creation: POST the new course, let the redirect refresh the
 * page props (the course list flows down from there), then select it by name.
 */
function createCourse(): void {
    const name = normalizedSearch.value;

    if (name === '' || creating.value) {
        return;
    }

    creating.value = true;
    createError.value = null;

    router.post(
        '/manage/courses',
        { name },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: async () => {
                await nextTick();
                const created = props.courses.find((course) => course.name === name);

                if (created && !isSelected(created.id)) {
                    model.value = [...model.value, created.id];
                }

                search.value = '';
            },
            onError: (errors) => {
                createError.value = errors.name ?? 'تعذر إنشاء المقرر.';
            },
            onFinish: () => {
                creating.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="space-y-2">
        <div v-if="selectedCourses.length" class="flex flex-wrap gap-1.5">
            <Badge v-for="course in selectedCourses" :key="course.id" variant="secondary" class="gap-1 pe-1">
                {{ course.name }}
                <button
                    type="button"
                    class="rounded-sm opacity-70 transition-opacity hover:opacity-100"
                    :aria-label="`إزالة المقرر ${course.name}`"
                    @click="toggleCourse(course.id)"
                >
                    <X class="size-3" />
                </button>
            </Badge>
        </div>

        <Input v-model="search" type="text" placeholder="ابحث عن مقرر…" :aria-label="'البحث في المقررات'" />

        <div class="max-h-40 overflow-y-auto rounded-md border border-input">
            <p v-if="!courses.length && !normalizedSearch" class="p-3 text-sm text-muted-foreground">
                لا توجد مقررات بعد. اكتب اسماً لإنشاء أول مقرر.
            </p>
            <button
                v-for="course in filteredCourses"
                :key="course.id"
                type="button"
                class="flex w-full items-center gap-2 px-3 py-2 text-start text-sm transition-colors hover:bg-accent hover:text-accent-foreground"
                @click="toggleCourse(course.id)"
            >
                <span class="flex size-4 shrink-0 items-center justify-center">
                    <Check v-if="isSelected(course.id)" class="size-4 text-primary" />
                </span>
                {{ course.name }}
            </button>
            <Button
                v-if="normalizedSearch && !hasExactMatch"
                type="button"
                variant="ghost"
                class="w-full justify-start gap-2 rounded-none px-3 text-primary"
                :disabled="creating"
                @click="createCourse"
            >
                <Loader2 v-if="creating" class="size-4 animate-spin" />
                <Plus v-else class="size-4" />
                إنشاء مقرر «{{ normalizedSearch }}»
            </Button>
            <p v-else-if="normalizedSearch && !filteredCourses.length" class="p-3 text-sm text-muted-foreground">لا نتائج مطابقة.</p>
        </div>

        <p v-if="createError" class="text-sm text-destructive-foreground">{{ createError }}</p>
    </div>
</template>
