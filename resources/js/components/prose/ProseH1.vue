<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ProseA from './ProseA.vue';

const page = usePage();
const pageData = computed(() => page.props.page as any);
</script>

<template>
    <h1 class="flex items-start justify-between gap-4">
        <div class="flex items-center gap-2">
            <i v-if="pageData?.icon" :class="pageData.icon" class="!size-8" />
            <slot />
        </div>
        <div v-if="pageData?.authors && pageData.authors.length > 0" class="text-sm text-muted-foreground">
            كتب بقلم:
            <template v-for="(author, index) in pageData.authors" :key="author.id">
                <ProseA v-if="author.url" :href="author.url" target="_blank">
                    {{ author.name }}
                </ProseA>
                <span v-else>
                    {{ author.name }}
                </span>
                <template v-if="index < pageData.authors.length - 1"> و</template>
            </template>
        </div>
    </h1>
</template>
