<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Check, ChevronDown, X } from 'lucide-vue-next';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import type { ParentOption } from './types';

const props = defineProps<{
    options: ParentOption[];
    /** Pages that cannot be chosen (the page itself and its descendants). */
    excludedIds?: number[];
}>();

const model = defineModel<number | null>({ required: true });

const open = ref(false);
const search = ref('');
const searchInput = useTemplateRef('searchInput');

const selected = computed(() => props.options.find((option) => option.id === model.value) ?? null);

const selectable = computed(() => {
    const excluded = new Set(props.excludedIds ?? []);

    return props.options.filter((option) => !excluded.has(option.id));
});

const filtered = computed(() => {
    const query = search.value.trim();

    return query === '' ? selectable.value : selectable.value.filter((option) => option.title.includes(query));
});

async function toggleOpen(): Promise<void> {
    open.value = !open.value;

    if (open.value) {
        search.value = '';
        await nextTick();
        searchInput.value?.$el?.focus?.();
    }
}

function select(id: number | null): void {
    model.value = id;
    open.value = false;
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center gap-1">
            <Button type="button" variant="outline" class="min-w-0 flex-1 justify-between font-normal" :aria-expanded="open" @click="toggleOpen">
                <span class="truncate" :class="selected ? '' : 'text-muted-foreground'">
                    {{ selected ? selected.title : 'بدون — صفحة رئيسية' }}
                </span>
                <ChevronDown class="size-4 shrink-0 text-muted-foreground" />
            </Button>
            <Button
                v-if="selected"
                type="button"
                variant="ghost"
                size="icon-sm"
                aria-label="إزالة الصفحة الأب (جعلها صفحة رئيسية)"
                @click="select(null)"
            >
                <X class="size-4" />
            </Button>
        </div>

        <div v-if="open" class="space-y-2 rounded-md border border-input p-2">
            <Input ref="searchInput" v-model="search" type="search" placeholder="ابحث عن صفحة…" aria-label="البحث في الصفحات" />
            <ul class="max-h-56 overflow-y-auto">
                <li>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-start text-sm transition-colors hover:bg-accent hover:text-accent-foreground"
                        @click="select(null)"
                    >
                        <span class="flex size-4 shrink-0 items-center justify-center">
                            <Check v-if="model === null" class="size-4 text-primary" />
                        </span>
                        بدون — صفحة رئيسية
                    </button>
                </li>
                <li v-for="option in filtered" :key="option.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-start text-sm transition-colors hover:bg-accent hover:text-accent-foreground"
                        :style="{ paddingInlineStart: `${0.5 + option.level * 1}rem` }"
                        @click="select(option.id)"
                    >
                        <span class="flex size-4 shrink-0 items-center justify-center">
                            <Check v-if="model === option.id" class="size-4 text-primary" />
                        </span>
                        <span class="truncate">{{ option.title }}</span>
                    </button>
                </li>
                <li v-if="!filtered.length" class="px-2 py-3 text-center text-sm text-muted-foreground">لا نتائج مطابقة.</li>
            </ul>
        </div>
    </div>
</template>
