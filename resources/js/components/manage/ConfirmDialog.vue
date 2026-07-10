<script setup lang="ts">
/**
 * Usage pattern (component-based, no composable):
 *
 *   const confirmingDeletion = ref(false);
 *   const deleting = ref(false);
 *
 *   <ConfirmDialog
 *       v-model:open="confirmingDeletion"
 *       title="حذف الصفحة"
 *       destructive
 *       confirm-label="حذف"
 *       :processing="deleting"
 *       @confirm="deletePage"
 *   >
 *       سيتم حذف الصفحة و٣ صفحات فرعية تابعة لها. يمكن استعادتها لاحقًا من سلة المحذوفات.
 *   </ConfirmDialog>
 *
 * The caller opens the dialog by setting `open`, passes the consequence text in the
 * default slot, runs the action on `@confirm`, and keeps `processing` true while it
 * runs (the dialog locks and shows a spinner). Close the dialog (`open = false`)
 * when the action finishes.
 */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Loader2 } from 'lucide-vue-next';

const props = withDefaults(
    defineProps<{
        title: string;
        confirmLabel?: string;
        cancelLabel?: string;
        destructive?: boolean;
        processing?: boolean;
    }>(),
    {
        confirmLabel: 'تأكيد',
        cancelLabel: 'إلغاء',
        destructive: false,
        processing: false,
    },
);

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();

function handleOpenChange(value: boolean): void {
    if (props.processing) {
        return;
    }

    open.value = value;

    if (!value) {
        emit('cancel');
    }
}

function handleCancel(): void {
    handleOpenChange(false);
}
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogContent :show-close-button="!processing">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    <slot />
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" :disabled="processing" @click="handleCancel">
                    {{ cancelLabel }}
                </Button>
                <Button :variant="destructive ? 'destructive' : 'default'" :disabled="processing" @click="emit('confirm')">
                    <Loader2 v-if="processing" class="size-4 animate-spin" />
                    {{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
