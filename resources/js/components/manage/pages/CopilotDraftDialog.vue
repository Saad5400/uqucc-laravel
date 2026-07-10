<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FilePlus2, Loader2 } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { draftPageSection } from './copilot';
import type { PageHtmlContent } from './types';

const props = defineProps<{
    pageId: number;
    /** The editor's CURRENT (possibly unsaved) content — the drafted section is appended after it. */
    content: PageHtmlContent;
}>();

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
    /** The document with the appended section — the parent fills the editor; the admin still saves explicitly. */
    apply: [content: PageHtmlContent];
}>();

const topic = ref('');
const processing = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        topic.value = '';
    }
});

async function submit(): Promise<void> {
    if (processing.value || topic.value.trim() === '') {
        return;
    }

    processing.value = true;

    try {
        const appended = await draftPageSection(props.pageId, props.content, topic.value.trim());

        emit('apply', appended);
        open.value = false;
        toast.success('تمت إضافة مسودة القسم', { description: 'أُضيف القسم إلى نهاية المحتوى — راجعه ثم احفظ الصفحة لاعتماده.' });
    } catch (error) {
        toast.error('تعذر توليد مسودة القسم', { description: error instanceof Error ? error.message : undefined });
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent :show-close-button="!processing">
            <DialogHeader>
                <DialogTitle>مسودة قسم جديد</DialogTitle>
                <DialogDescription>يكتب المساعد قسماً جديداً عن الموضوع المحدد ويضيفه إلى نهاية المحتوى لمراجعته قبل الحفظ.</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="copilot-draft-topic">موضوع القسم</Label>
                    <Input
                        id="copilot-draft-topic"
                        v-model="topic"
                        type="text"
                        required
                        maxlength="200"
                        placeholder="مثال: شروط التحويل بين التخصصات"
                    />
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="processing" @click="open = false">إلغاء</Button>
                    <Button type="submit" :disabled="processing || topic.trim() === ''">
                        <Loader2 v-if="processing" class="size-4 animate-spin" />
                        <FilePlus2 v-else class="size-4" />
                        توليد المسودة
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
