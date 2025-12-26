<template>
    <Link v-if="shouldUseInertiaLink" :href="props.href" :target="linkTarget">
        <slot />
    </Link>
    <a v-else :href="props.href" :target="linkTarget">
        <slot />
    </a>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
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

const normalizedHref = computed(() => props.href || '');
const linkTarget = computed(() => {
    if (normalizedHref.value.startsWith('/documents') || normalizedHref.value.startsWith('http')) {
        return '_blank';
    }

    return props.target || '_self';
});

const isHttpUrl = computed(() => normalizedHref.value.startsWith('http://') || normalizedHref.value.startsWith('https://'));
const isHashLink = computed(() => normalizedHref.value.startsWith('#'));
const isRelativePath = computed(
    () => normalizedHref.value.startsWith('/') || normalizedHref.value.startsWith('./') || normalizedHref.value.startsWith('../')
);
const isMailOrTel = computed(() => normalizedHref.value.startsWith('mailto:') || normalizedHref.value.startsWith('tel:'));
const isSameHost = computed(() => {
    if (!isHttpUrl.value || typeof window === 'undefined') return false;

    try {
        return new URL(normalizedHref.value).host === window.location.host;
    } catch {
        return false;
    }
});

const shouldUseInertiaLink = computed(() => {
    if (!normalizedHref.value || isMailOrTel.value) return false;
    if (isHashLink.value || isRelativePath.value) return true;

    return isHttpUrl.value && isSameHost.value;
});
</script>
