<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { watch } from 'vue';

/** Server-side bounds mirrored client-side (StoreCorpusTextRequest). */
const MIN_CONTENT_CHARS = 50;
const MAX_CONTENT_CHARS = 500_000;

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    title: '',
    content: '',
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.reset();
    }
});

function submit(): void {
    const contentLength = form.content.trim().length;

    if (contentLength < MIN_CONTENT_CHARS) {
        form.setError('content', `النص قصير جداً — أدخل ${MIN_CONTENT_CHARS} حرفاً على الأقل.`);

        return;
    }

    if (contentLength > MAX_CONTENT_CHARS) {
        form.setError('content', 'النص يتجاوز الحد الأقصى (٥٠٠ ألف حرف).');

        return;
    }

    form.post('/manage/corpus/text', {
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
        <DialogContent class="sm:max-w-2xl" :show-close-button="!form.processing">
            <DialogHeader>
                <DialogTitle>لصق نص</DialogTitle>
                <DialogDescription>يُحفظ النص كمستند جاهز مباشرة (دون استخراج) ثم يُفهرس في البحث الذكي.</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="corpus-paste-title">العنوان</Label>
                    <Input
                        id="corpus-paste-title"
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
                    <Label for="corpus-paste-content">النص</Label>
                    <Textarea
                        id="corpus-paste-content"
                        v-model="form.content"
                        dir="auto"
                        rows="12"
                        required
                        :maxlength="MAX_CONTENT_CHARS"
                        class="max-h-96 min-h-48"
                        :aria-invalid="form.errors.content ? true : undefined"
                    />
                    <p class="text-xs text-muted-foreground">الصق نص اللائحة أو الدليل كما هو — ٥٠ حرفاً على الأقل، ويُقبل تنسيق ماركداون.</p>
                    <p v-if="form.errors.content" class="text-sm text-destructive-foreground">{{ form.errors.content }}</p>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="open = false">إلغاء</Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        إضافة النص
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
