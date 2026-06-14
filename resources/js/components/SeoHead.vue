<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export interface SeoData {
    title: string;
    fullTitle: string;
    description: string;
    canonical: string;
    ogType: string;
    schema: Record<string, unknown>[];
}

const props = defineProps<{
    seo: SeoData;
}>();

const page = usePage();

const origin = computed(() => {
    try {
        return new URL(props.seo.canonical).origin;
    } catch {
        return '';
    }
});

const ogImageUrl = computed(() => {
    const path = page.url === '/' ? '' : page.url.split('?')[0];
    return `${origin.value}/_og-image${path}`;
});
</script>

<template>
    <Head>
        <title head-key="title">{{ seo.fullTitle }}</title>
        <meta head-key="description" name="description" :content="seo.description" />
        <meta head-key="og:type" property="og:type" :content="seo.ogType" />
        <meta head-key="og:title" property="og:title" :content="seo.title" />
        <meta head-key="og:description" property="og:description" :content="seo.description" />
        <meta head-key="og:url" property="og:url" :content="seo.canonical" />
        <meta head-key="og:image" property="og:image" :content="ogImageUrl" />
        <meta head-key="og:image:alt" property="og:image:alt" :content="seo.title" />
        <meta head-key="twitter:title" name="twitter:title" :content="seo.title" />
        <meta head-key="twitter:description" name="twitter:description" :content="seo.description" />
        <meta head-key="twitter:url" name="twitter:url" :content="seo.canonical" />
        <meta head-key="twitter:image" name="twitter:image" :content="ogImageUrl" />
        <link head-key="canonical" rel="canonical" :href="seo.canonical" />
    </Head>
</template>
