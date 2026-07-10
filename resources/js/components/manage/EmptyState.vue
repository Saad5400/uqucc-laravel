<script setup lang="ts">
import type { FunctionalComponent } from 'vue';

defineProps<{
    icon?: FunctionalComponent;
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border px-6 py-12 text-center">
        <div v-if="icon" class="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <component :is="icon" class="size-6" />
        </div>
        <div class="space-y-1">
            <p class="font-medium">{{ title }}</p>
            <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
        </div>
        <div v-if="$slots.default" class="mt-2">
            <slot />
        </div>
    </div>
</template>
