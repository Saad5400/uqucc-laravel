<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Loader2, Sparkles } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { improvePageText } from './copilot';
import type { PageHtmlContent } from './types';

const props = defineProps<{
    pageId: number;
    /** The editor's CURRENT (possibly unsaved) content — the copilot improves what the admin sees. */
    content: PageHtmlContent;
}>();

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
    /** The improved document — the parent fills the editor; the admin still saves explicitly. */
    apply: [content: PageHtmlContent];
}>();

const instruction = ref('');
const processing = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        instruction.value = '';
    }
});

async function submit(): Promise<void> {
    if (processing.value) {
        return;
    }

    processing.value = true;

    try {
        const improved = await improvePageText(props.pageId, props.content, instruction.value.trim());

        emit('apply', improved);
        open.value = false;
        toast.success('تم تحسين النص', { description: 'راجع النتيجة في المحرر ثم احفظ الصفحة لاعتمادها.' });
    } catch (error) {
        toast.error('تعذر تحسين النص', { description: error instanceof Error ? error.message : undefined });
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent :show-close-button="!processing">
            <DialogHeader>
                <DialogTitle>تحسين النص بالذكاء الاصطناعي</DialogTitle>
                <DialogDescription>
                    يعيد المساعد صياغة المحتوى الحالي ويملأ الحقل بالنتيجة لمراجعتها قبل الحفظ. ملاحظة: قد تتحول العناصر المخصصة (تنبيه/قابل للطي) إلى
                    نص عادي.
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="copilot-improve-instruction">تعليمات إضافية (اختياري)</Label>
                    <Textarea
                        id="copilot-improve-instruction"
                        v-model="instruction"
                        rows="3"
                        placeholder="مثال: اجعل الأسلوب أكثر رسمية، أو لخّص الفقرات الطويلة"
                    />
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="processing" @click="open = false">إلغاء</Button>
                    <Button type="submit" :disabled="processing">
                        <Loader2 v-if="processing" class="size-4 animate-spin" />
                        <Sparkles v-else class="size-4" />
                        تحسين
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
