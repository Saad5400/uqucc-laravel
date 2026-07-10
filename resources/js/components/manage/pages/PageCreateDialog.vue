<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import ParentPicker from './ParentPicker.vue';
import type { ParentOption } from './types';

const props = defineProps<{
    parentOptions: ParentOption[];
    /** Preselected parent when the dialog opens from "إضافة صفحة فرعية". */
    presetParentId?: number | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm<{ title: string; parent_id: number | null }>({
    title: '',
    parent_id: null,
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.title = '';
        form.parent_id = props.presetParentId ?? null;
    }
});

/**
 * Approximate client-side preview of the server-generated slug. The server
 * transliterates Arabic to Latin (Str::slug), which the client does not
 * replicate — for non-Latin titles an explanatory note is shown instead.
 */
const previewSlug = computed(() => {
    const latin = form.title
        .trim()
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/[\s_]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');

    return latin === '' ? null : `/${latin}`;
});

function submit(): void {
    form.post('/manage/pages', {
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent :show-close-button="!form.processing">
            <DialogHeader>
                <DialogTitle>صفحة جديدة</DialogTitle>
                <DialogDescription>أدخل عنوان الصفحة وموقعها في الشجرة، وستفتح مساحة العمل لتحرير محتواها.</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="new-page-title">العنوان</Label>
                    <Input id="new-page-title" v-model="form.title" type="text" required :aria-invalid="form.errors.title ? true : undefined" />
                    <p v-if="form.errors.title" class="text-sm text-destructive-foreground">{{ form.errors.title }}</p>
                    <p v-if="form.title.trim()" class="text-xs text-muted-foreground">
                        <template v-if="previewSlug">
                            الرابط المتوقع: <code dir="ltr" class="rounded bg-muted px-1">{{ previewSlug }}</code>
                        </template>
                        <template v-else>سيُولَّد الرابط تلقائياً من العنوان بحروف لاتينية، ويمكن تعديله لاحقاً من تبويب الإعدادات.</template>
                    </p>
                </div>
                <div class="space-y-2">
                    <Label>الصفحة الأب</Label>
                    <ParentPicker v-model="form.parent_id" :options="parentOptions" />
                    <p v-if="form.errors.parent_id" class="text-sm text-destructive-foreground">{{ form.errors.parent_id }}</p>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="open = false">إلغاء</Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        إنشاء وفتح
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
