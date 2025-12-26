<script setup lang="ts">
import { ref, onMounted, useSlots, h, type VNode } from 'vue';

const isMounted = ref(false);
const slots = useSlots();

onMounted(() => {
    isMounted.value = true;
});
</script>

<template>
    <template v-if="isMounted">
        <slot />
    </template>
    <template v-else>
        <slot name="fallback" />
    </template>
</template>


