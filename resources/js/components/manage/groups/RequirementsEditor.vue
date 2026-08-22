<script setup lang="ts">
/**
 * The join checklist as a repeatable list of one-liners.
 *
 * A textarea would be less code, but the public page renders these as a
 * checklist — one line per item — and a free-text box makes "one item" a
 * convention the admin has to remember rather than something the editor
 * enforces. Blank rows are dropped on submit, so a stray empty field is never
 * an error the admin has to go fix.
 */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

const MAX_REQUIREMENTS = 10;

const model = defineModel<string[]>({ default: () => [] });

const canAdd = computed(() => model.value.length < MAX_REQUIREMENTS);

function updateAt(index: number, value: string): void {
    model.value = model.value.map((requirement, current) => (current === index ? value : requirement));
}

function removeAt(index: number): void {
    model.value = model.value.filter((_, current) => current !== index);
}

function add(): void {
    if (canAdd.value) {
        model.value = [...model.value, ''];
    }
}
</script>

<template>
    <div class="space-y-2">
        <p v-if="!model.length" class="text-sm text-muted-foreground">لا توجد شروط. أضف ما يجب على الطالب إرساله للمشرف.</p>

        <div v-for="(requirement, index) in model" :key="index" class="flex items-center gap-2">
            <Input
                :model-value="requirement"
                type="text"
                :aria-label="`الشرط رقم ${index + 1}`"
                placeholder="مثال: صورة من البوابة الأكاديمية"
                @update:model-value="updateAt(index, String($event))"
            />
            <Button type="button" variant="ghost" size="icon" :aria-label="`حذف الشرط رقم ${index + 1}`" @click="removeAt(index)">
                <Trash2 />
            </Button>
        </div>

        <Button type="button" variant="outline" size="sm" :disabled="!canAdd" :title="canAdd ? undefined : 'بلغت الحد الأقصى ١٠ شروط'" @click="add">
            <Plus />
            إضافة شرط
        </Button>
    </div>
</template>
