<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import type { CourseRow } from './types';

const props = defineProps<{
    course: CourseRow | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const isEditing = computed(() => props.course !== null);

const form = useForm({ name: '' });

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.name = props.course?.name ?? '';
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

    if (props.course) {
        form.put(`/manage/courses/${props.course.id}`, options);
    } else {
        form.post('/manage/courses', options);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent :show-close-button="!form.processing">
            <DialogHeader>
                <DialogTitle>{{ isEditing ? 'تعديل المقرر' : 'إضافة مقرر' }}</DialogTitle>
                <DialogDescription>
                    {{ isEditing ? 'عدّل اسم المقرر.' : 'أدخل اسم المقرر الجديد.' }}
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="course-name">اسم المقرر</Label>
                    <Input id="course-name" v-model="form.name" type="text" required :aria-invalid="form.errors.name ? true : undefined" />
                    <p v-if="form.errors.name" class="text-sm text-destructive-foreground">{{ form.errors.name }}</p>
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
