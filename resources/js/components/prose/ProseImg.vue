<template>
    <img class="size-full max-w-4xl" :src="props.src" :alt="props.alt" :width="props.width" :height="props.height" />
</template>

<script setup lang="ts">
const props = defineProps({
    src: {
        type: String,
        default: '',
    },
    alt: {
        type: String,
        default: '',
    },
    width: {
        type: [String, Number],
        default: undefined,
    },
    height: {
        type: [String, Number],
        default: undefined,
    },
});
</script>
