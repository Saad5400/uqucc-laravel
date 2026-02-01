<script setup lang="ts">
import { ref, onMounted } from 'vue';

const isMounted = ref(false);

onMounted(() => {
    if (import.meta.env.SSR) return;

    isMounted.value = true;

    requestAnimationFrame(() => {
        const hash = window.location.hash;
        if (hash) {
            document.querySelector(hash)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});
</script>

<template>
    <slot v-if="isMounted" />
</template>
