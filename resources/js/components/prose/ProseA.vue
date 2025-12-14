<template>
    <a :href="props.href" :target="target">
        <slot />
    </a>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { PropType } from 'vue';

const props = defineProps({
    href: {
        type: String,
        default: '',
    },
    target: {
        type: String as PropType<'_blank' | '_parent' | '_self' | '_top' | (string & object) | null | undefined>,
        default: undefined,
        required: false,
    },
});

const target = computed(() => {
    if (props.href.startsWith('/documents') || props.href.startsWith('http')) {
        return '_blank';
    }

    return props.target || '_self';
});
</script>
