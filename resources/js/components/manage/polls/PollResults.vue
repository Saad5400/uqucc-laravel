<script setup lang="ts">
/**
 * What a finished poll came back with: every option by share of the vote,
 * ranked. The bar is the quick read and the numbers are the exact one, so a
 * near-tie can still be told apart.
 */
import { computed } from 'vue';
import type { Poll } from './types';
import { rankedResults } from './types';

const props = defineProps<{ poll: Poll }>();

const rows = computed(() => rankedResults(props.poll));
</script>

<template>
    <ol class="space-y-2">
        <li v-for="(row, index) in rows" :key="row.option" class="space-y-1">
            <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="min-w-0 flex-1 truncate" :class="index === 0 && row.votes > 0 ? 'font-medium' : ''">{{ row.option }}</span>
                <span class="shrink-0 text-muted-foreground tabular-nums">{{ row.percent }}٪ ({{ row.votes }})</span>
            </div>
            <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full transition-all"
                    :class="index === 0 && row.votes > 0 ? 'bg-primary' : 'bg-primary/40'"
                    :style="{ width: `${row.percent}%` }"
                />
            </div>
        </li>
    </ol>
</template>
