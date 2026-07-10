<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { watch } from 'vue';

const open = defineModel<boolean>('open', { default: false });

const form = useForm<{ title: string; file: File | null }>({
    title: '',
    file: null,
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.reset();
    }
});

function handleFileChange(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit(): void {
    form.post('/manage/corpus', {
        preserveScroll: true,
        preserveState: true,
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
                <DialogTitle>رفع مستند</DialogTitle>
                <DialogDescription>يُستخرج نص المستند تلقائياً بعد الرفع ثم يُفهرس في البحث الذكي.</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="corpus-upload-title">العنوان</Label>
                    <Input
                        id="corpus-upload-title"
                        v-model="form.title"
                        type="text"
                        required
                        maxlength="255"
                        :aria-invalid="form.errors.title ? true : undefined"
                    />
                    <p class="text-xs text-muted-foreground">اسم واضح للمستند كما سيظهر في نتائج البحث الذكي (مثال: لائحة الدراسة والاختبارات).</p>
                    <p v-if="form.errors.title" class="text-sm text-destructive-foreground">{{ form.errors.title }}</p>
                </div>
                <div class="space-y-2">
                    <Label for="corpus-upload-file">الملف</Label>
                    <Input
                        id="corpus-upload-file"
                        type="file"
                        accept="application/pdf,image/png,image/jpeg,image/webp"
                        required
                        :aria-invalid="form.errors.file ? true : undefined"
                        @change="handleFileChange"
                    />
                    <p class="text-xs text-muted-foreground">
                        PDF أو صورة (PNG / JPG / WebP) بحجم أقصى 20 ميجابايت. تُستخرج النصوص تلقائياً بعد الرفع.
                    </p>
                    <p v-if="form.errors.file" class="text-sm text-destructive-foreground">{{ form.errors.file }}</p>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="open = false">إلغاء</Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        رفع المستند
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
