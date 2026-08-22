<script setup lang="ts">
/**
 * What the join message has to contain, as numbered steps rather than ticks:
 * these are things the student still has to do, and a checkmark reads as
 * something already done.
 */
import { arabicDigits } from '@/lib/arabic';

defineProps<{
    requirements: string[];
}>();
</script>

<template>
    <ol class="m-0 list-none space-y-2 p-0">
        <li v-for="(requirement, index) in requirements" :key="index" class="flex items-start gap-2.5">
            <span
                class="mt-px flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[0.7rem] font-semibold text-primary tabular-nums"
                aria-hidden="true"
            >
                {{ arabicDigits(index + 1) }}
            </span>
            <span class="text-sm leading-relaxed">{{ requirement }}</span>
        </li>
    </ol>
</template>
