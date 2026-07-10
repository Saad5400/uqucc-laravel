<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import CourseMultiSelect from './CourseMultiSelect.vue';
import type { TutorCourse, TutorRow } from './types';

const props = defineProps<{
    tutor: TutorRow | null;
    courses: TutorCourse[];
}>();

const open = defineModel<boolean>('open', { default: false });

const isEditing = computed(() => props.tutor !== null);

const form = useForm<{ name: string; url: string; course_ids: number[] }>({
    name: '',
    url: '',
    course_ids: [],
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.name = props.tutor?.name ?? '';
        form.url = props.tutor?.url ?? '';
        form.course_ids = props.tutor?.courses.map((course) => course.id) ?? [];
    }
});

function submit(): void {
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.tutor) {
        form.transform((data) => ({ ...data, url: data.url.trim() === '' ? null : data.url.trim() })).put(
            `/manage/tutors/${props.tutor.id}`,
            options,
        );
    } else {
        form.transform((data) => ({ ...data, url: data.url.trim() === '' ? null : data.url.trim() })).post('/manage/tutors', options);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent :show-close-button="!form.processing">
            <DialogHeader>
                <DialogTitle>{{ isEditing ? 'تعديل الخصوصي' : 'إضافة خصوصي' }}</DialogTitle>
                <DialogDescription>
                    {{ isEditing ? 'عدّل بيانات الخصوصي والمقررات المرتبطة به.' : 'أدخل اسم الخصوصي ورابطه ومقرراته (اختياريان).' }}
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="tutor-name">الاسم</Label>
                    <Input id="tutor-name" v-model="form.name" type="text" required :aria-invalid="form.errors.name ? true : undefined" />
                    <p v-if="form.errors.name" class="text-sm text-destructive-foreground">{{ form.errors.name }}</p>
                </div>
                <div class="space-y-2">
                    <Label for="tutor-url">الرابط</Label>
                    <Input
                        id="tutor-url"
                        v-model="form.url"
                        type="url"
                        dir="ltr"
                        class="text-start"
                        placeholder="https://example.com"
                        :aria-invalid="form.errors.url ? true : undefined"
                    />
                    <p v-if="form.errors.url" class="text-sm text-destructive-foreground">{{ form.errors.url }}</p>
                </div>
                <div class="space-y-2">
                    <Label>المقررات</Label>
                    <CourseMultiSelect v-model="form.course_ids" :courses="courses" />
                    <p v-if="form.errors.course_ids" class="text-sm text-destructive-foreground">{{ form.errors.course_ids }}</p>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="open = false">إلغاء</Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        {{ isEditing ? 'حفظ' : 'إضافة' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
