<script setup lang="ts">
import { SidebarMenuButton, useSidebar } from '@/components/ui/sidebar';
import { Icon } from '@iconify/vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

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
    <SidebarMenuButton
        class="h-full text-start transition-colors data-[active=true]:bg-primary/10 data-[active=true]:font-semibold data-[active=true]:text-primary data-[active=true]:hover:bg-primary/15"
        as-child
        :is-active="isActive"
    >
        <Link :href="item.path" @click="sidebar.setOpenMobile(false)">
            <Icon v-if="item.icon" :icon="item.icon" class="!size-5" :class="isActive ? 'text-primary' : 'text-muted-foreground'" />
            {{ item.title }}
        </Link>
    </SidebarMenuButton>
</template>
