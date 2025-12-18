<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Icon } from '@iconify/vue';
import { SidebarMenuButton, useSidebar } from '@/components/ui/sidebar';

interface NavigationItem {
    id: number;
    title: string;
    path: string;
    icon?: string;
    children?: NavigationItem[];
}

const props = defineProps<{
    item: NavigationItem;
}>();

const page = usePage();
const sidebar = useSidebar();

const currentPath = computed(() => page.url);
const isActive = computed(() => currentPath.value === props.item.path);
</script>

<template>
    <SidebarMenuButton class="h-full text-start" as-child :is-active="isActive">
        <Link :href="item.path" @click="sidebar.setOpenMobile(false)">
            <Icon v-if="item.icon" :icon="item.icon" class="!size-5" />
            {{ item.title }}
        </Link>
    </SidebarMenuButton>
</template>
