<script setup lang="ts">
import { Badge, type BadgeVariants } from '@/components/ui/badge';
import { computed } from 'vue';
import { eventLabels } from './types';

const props = defineProps<{
    event: string | null;
}>();

const variant = computed<BadgeVariants['variant']>(() => {
    switch (props.event) {
        case 'deleted':
            return 'destructive';
        case 'updated':
            return 'secondary';
        default:
            return 'outline';
    }
});

const label = computed(() => (props.event ? (eventLabels[props.event] ?? props.event) : '—'));
</script>

<template>
    <!-- "created" gets a positive tint from the chart-2 token (no success token exists). -->
    <Badge :variant="variant" :class="event === 'created' ? 'border-transparent bg-(--chart-2)/15 text-(--chart-2)' : ''">
        {{ label }}
    </Badge>
</template>
