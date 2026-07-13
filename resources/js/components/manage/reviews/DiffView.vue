<script setup lang="ts">
import { diffLines } from '@/lib/diff';
import { computed } from 'vue';

const props = defineProps<{
    old: string;
    new: string;
}>();

const lines = computed(() => diffLines(props.old, props.new));

const hasChanges = computed(() => lines.value.some((line) => line.type !== 'context'));

function marker(type: string): string {
    if (type === 'add') {
        return '+';
    }

    return type === 'remove' ? '−' : '';
}
</script>

<template>
    <div class="overflow-hidden rounded-md border border-border text-sm">
        <p v-if="!hasChanges" class="bg-muted/40 px-3 py-2 text-xs text-muted-foreground">لا فرق في هذا الحقل.</p>

        <div v-else class="divide-y divide-border/60">
            <div
                v-for="(line, index) in lines"
                :key="index"
                class="flex items-start gap-0"
                :class="{
                    'bg-emerald-500/10': line.type === 'add',
                    'bg-destructive/10': line.type === 'remove',
                }"
            >
                <span
                    class="w-10 shrink-0 border-e border-border/60 px-2 py-1 text-end text-xs text-muted-foreground tabular-nums select-none"
                    dir="ltr"
                    aria-hidden="true"
                >
                    {{ line.oldNumber ?? '' }}
                </span>
                <span
                    class="w-10 shrink-0 border-e border-border/60 px-2 py-1 text-end text-xs text-muted-foreground tabular-nums select-none"
                    dir="ltr"
                    aria-hidden="true"
                >
                    {{ line.newNumber ?? '' }}
                </span>
                <span
                    class="w-5 shrink-0 py-1 text-center font-medium select-none"
                    :class="{
                        'text-emerald-600 dark:text-emerald-400': line.type === 'add',
                        'text-destructive': line.type === 'remove',
                    }"
                    aria-hidden="true"
                >
                    {{ marker(line.type) }}
                </span>
                <span class="min-w-0 flex-1 py-1 pe-3 leading-relaxed whitespace-pre-wrap" dir="auto">{{ line.text || ' ' }}</span>
            </div>
        </div>
    </div>
</template>
