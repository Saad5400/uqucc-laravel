<template>
    <h5 :id="props.id ? slugifiedId : undefined">
        <a v-if="props.id" class="text-foreground font-normal" :href="`#${slugifiedId}`">
            <slot />
        </a>
        <slot v-else />
    </h5>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ id?: string }>();

const slugifiedId = computed(() => {
    if (!props.id) return '';
    return props.id
        .toLowerCase()
        .replace(/[^a-z0-9\u0600-\u06FF]+/g, '-')
        .replace(/^-+|-+$/g, '');
});
</script>
