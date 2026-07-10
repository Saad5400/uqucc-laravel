<script setup lang="ts">
import type { Component } from 'vue';

import { cn } from '@/lib/utils';

const props = defineProps<{
    icon: Component;
    title: string;
    active?: boolean;
    disabled?: boolean;
    size?: 'sm' | 'default';
}>();

const emit = defineEmits<{
    (e: 'click'): void;
}>();
</script>

<template>
    <button
        type="button"
        :title="title"
        :aria-label="title"
        :aria-pressed="active === undefined ? undefined : active"
        :disabled="disabled"
        :class="
            cn(
                'inline-flex items-center justify-center rounded-md text-muted-foreground transition-colors',
                'hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                'disabled:pointer-events-none disabled:opacity-50',
                props.size === 'sm' ? 'size-6' : 'size-8',
                active && 'bg-accent text-accent-foreground',
            )
        "
        @mousedown.prevent
        @click="emit('click')"
    >
        <component :is="icon" :class="props.size === 'sm' ? 'size-3.5' : 'size-4'" />
    </button>
</template>
