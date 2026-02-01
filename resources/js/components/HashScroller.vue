<script setup lang="ts">
import { onMounted } from 'vue';

onMounted(() => {
    const hash = window.location.hash;
    if (hash) {
        document.querySelector(hash)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});
</script>

<template>
    <slot />
</template>
