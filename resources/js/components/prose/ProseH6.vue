<template>
    <h6 :id="props.id ? slugifiedId : undefined">
        <Link v-if="props.id" class="text-foreground font-normal" :href="`#${slugifiedId}`">
            <slot />
        </Link>
        <slot v-else />
    </h6>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
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
