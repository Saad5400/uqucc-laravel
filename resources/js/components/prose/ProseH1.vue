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
        <div v-if="pageData?.users && pageData.users.length > 0" class="text-sm text-muted-foreground">
            كتب بقلم:
            <template v-for="(user, index) in pageData.users" :key="user.id">
                <ProseA v-if="user.url" :href="user.url" target="_blank">
                    {{ user.name }}
                </ProseA>
                <span v-else>
                    {{ user.name }}
                </span>
                <template v-if="index < pageData.users.length - 1"> و</template>
            </template>
        </div>
    </h1>
</template>
