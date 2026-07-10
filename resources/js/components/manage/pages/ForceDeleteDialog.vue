<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import type { TrashedPageRow } from './types';

const props = defineProps<{
    page: TrashedPageRow | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const typedTitle = ref('');
const processing = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        typedTitle.value = '';
    }
});

/** Pages that ever had children get the heavier typed-name confirmation. */
const requiresTypedName = computed(() => (props.page?.children_count ?? 0) > 0);

const confirmDisabled = computed(() => processing.value || (requiresTypedName.value && typedTitle.value.trim() !== props.page?.title));

function forceDelete(): void {
    if (!props.page || confirmDisabled.value) {
        return;
    }

    processing.value = true;

    router.delete(`/manage/pages/${props.page.id}/force`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent :show-close-button="!processing">
            <DialogHeader>
                <DialogTitle>حذف نهائي</DialogTitle>
                <DialogDescription v-if="page">
                    <template v-if="requiresTypedName">
                        سيتم حذف الصفحة «{{ page.title }}» نهائياً مع أي صفحات فرعية محذوفة تابعة لها، ولا يمكن التراجع عن ذلك.
                    </template>
                    <template v-else>سيتم حذف الصفحة «{{ page.title }}» نهائياً ولا يمكن التراجع عن ذلك.</template>
                </DialogDescription>
            </DialogHeader>
            <div v-if="page && requiresTypedName" class="space-y-2">
                <Label for="force-delete-title">اكتب عنوان الصفحة «{{ page.title }}» للتأكيد</Label>
                <Input id="force-delete-title" v-model="typedTitle" type="text" autocomplete="off" />
            </div>
            <DialogFooter>
                <Button variant="outline" :disabled="processing" @click="open = false">إلغاء</Button>
                <Button variant="destructive" :disabled="confirmDisabled" @click="forceDelete">
                    <Loader2 v-if="processing" class="size-4 animate-spin" />
                    حذف نهائياً
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
