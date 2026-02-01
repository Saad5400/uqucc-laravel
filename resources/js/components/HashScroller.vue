<script setup lang="ts">
if (typeof window !== 'undefined') {
    requestAnimationFrame(() => {
        const hash = window.location.hash;
        if (hash) {
            document.querySelector(hash)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
}
</script>

<template>
    <slot />
</template>
