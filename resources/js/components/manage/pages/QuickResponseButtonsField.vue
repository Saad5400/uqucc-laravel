<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useSortableList } from '@/composables/useSortableList';
import { ArrowDown, ArrowUp, ChevronDown, ChevronLeft, GripVertical, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { buttonSizeLabels, type QuickResponseButtonRow, type QuickResponseButtonSize } from './types';

const props = defineProps<{
    /** Server validation errors keyed like `quick_response_buttons.0.text`. */
    errors: Record<string, string>;
}>();

const model = defineModel<QuickResponseButtonRow[]>({ required: true });

let nextLocalId = -1;

const expandedIds = ref<Set<number>>(new Set());

function toggleExpanded(id: number): void {
    const next = new Set(expandedIds.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    expandedIds.value = next;
}

/** Local reorder only — the new order is saved with the tab's explicit save. */
const { items, draggingId, startDrag, dragOver, endDrag, moveUp, moveDown } = useSortableList(
    () => model.value,
    (ids) => {
        model.value = ids.map((id) => model.value.find((row) => row.id === id)).filter((row): row is QuickResponseButtonRow => row !== undefined);

        return Promise.resolve();
    },
);

function addButton(): void {
    const row: QuickResponseButtonRow = { id: nextLocalId--, text: '', url: '', size: 'full' };

    model.value = [...model.value, row];
    expandedIds.value = new Set([...expandedIds.value, row.id]);
}

function removeButton(id: number): void {
    model.value = model.value.filter((row) => row.id !== id);
}

function errorFor(index: number, field: 'text' | 'url' | 'size'): string | undefined {
    return props.errors[`quick_response_buttons.${index}.${field}`];
}
</script>

<template>
    <div class="space-y-2">
        <ul v-if="items.length" class="space-y-2">
            <li
                v-for="(row, index) in items"
                :key="row.id"
                class="rounded-md border border-border"
                :class="{ 'opacity-50': draggingId === row.id }"
                draggable="true"
                @dragstart="startDrag(row, $event)"
                @dragover="dragOver(row, $event)"
                @dragend="endDrag($event)"
                @drop.prevent
            >
                <div class="flex items-center gap-1.5 p-2">
                    <GripVertical class="size-4 shrink-0 cursor-grab text-muted-foreground/60" aria-hidden="true" />
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-2 text-start"
                        :aria-expanded="expandedIds.has(row.id)"
                        @click="toggleExpanded(row.id)"
                    >
                        <ChevronDown v-if="expandedIds.has(row.id)" class="size-4 shrink-0 text-muted-foreground" />
                        <ChevronLeft v-else class="size-4 shrink-0 text-muted-foreground" />
                        <span class="truncate text-sm font-medium" :class="{ 'text-muted-foreground': !row.text.trim() }">
                            {{ row.text.trim() || 'زر بلا عنوان' }}
                        </span>
                    </button>
                    <Button variant="ghost" size="icon-sm" aria-label="نقل الزر لأعلى" @click="moveUp(row)">
                        <ArrowUp />
                    </Button>
                    <Button variant="ghost" size="icon-sm" aria-label="نقل الزر لأسفل" @click="moveDown(row)">
                        <ArrowDown />
                    </Button>
                    <Button variant="ghost" size="icon-sm" class="text-destructive-foreground" aria-label="حذف الزر" @click="removeButton(row.id)">
                        <Trash2 />
                    </Button>
                </div>

                <div v-if="expandedIds.has(row.id)" class="grid gap-4 border-t border-border p-3 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label :for="`qr-button-text-${row.id}`">عنوان الزر</Label>
                        <Input
                            :id="`qr-button-text-${row.id}`"
                            v-model="row.text"
                            type="text"
                            maxlength="50"
                            :aria-invalid="errorFor(index, 'text') ? true : undefined"
                        />
                        <p v-if="errorFor(index, 'text')" class="text-sm text-destructive-foreground">{{ errorFor(index, 'text') }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label :for="`qr-button-url-${row.id}`">رابط الزر</Label>
                        <Input
                            :id="`qr-button-url-${row.id}`"
                            v-model="row.url"
                            type="url"
                            dir="ltr"
                            class="text-start"
                            placeholder="https://example.com"
                            :aria-invalid="errorFor(index, 'url') ? true : undefined"
                        />
                        <p v-if="errorFor(index, 'url')" class="text-sm text-destructive-foreground">{{ errorFor(index, 'url') }}</p>
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label>حجم الزر</Label>
                        <Select :model-value="row.size" @update:model-value="(value) => (row.size = value as QuickResponseButtonSize)">
                            <SelectTrigger class="w-full sm:w-72">
                                <SelectValue placeholder="حجم الزر" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="(label, size) in buttonSizeLabels" :key="size" :value="size">{{ label }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">عدد الأزرار في السطر الواحد.</p>
                        <p v-if="errorFor(index, 'size')" class="text-sm text-destructive-foreground">{{ errorFor(index, 'size') }}</p>
                    </div>
                </div>
            </li>
        </ul>

        <Button type="button" variant="outline" size="sm" @click="addButton">
            <Plus />
            إضافة زر
        </Button>
    </div>
</template>
