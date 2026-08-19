<script setup lang="ts">
import { computed } from 'vue';

/**
 * Live "used / allowed" counter for a length-capped field, so the limit is
 * visible while typing instead of arriving as a validation error on save.
 */
const props = defineProps<{
    value?: string | null | undefined;
    max: number;
    /** An explicit length, when the counted value is not the raw string (e.g. HTML text length). */
    count?: number;
}>();

const length = computed(() => props.count ?? (props.value ?? '').length);
</script>

<template>
    <span
        dir="ltr"
        class="text-xs tabular-nums"
        :class="length > max ? 'font-medium text-destructive-foreground' : 'text-muted-foreground'"
        :title="`الحد الأقصى ${max} حرف`"
    >
        {{ length }} / {{ max }}
    </span>
</template>
