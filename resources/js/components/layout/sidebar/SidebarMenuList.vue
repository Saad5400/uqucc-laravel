<script setup lang="ts">
import { SidebarMenuItem } from '@/components/ui/sidebar';
import SidebarMenuLink from './SidebarMenuLink.vue';
import SidebarMenuCollapsible from './SidebarMenuCollapsible.vue';

interface NavigationItem {
    id: number;
    title: string;
    path: string;
    icon?: string;
    children?: NavigationItem[];
}

defineProps<{
    items: NavigationItem[];
}>();
</script>

<template>
    <ul class="space-y-1">
        <!-- For leaf nodes: SidebarMenuItem itself renders a <li>, so no outer wrapper needed -->
        <template v-for="item in items" :key="item.id">
            <!-- leaf node -->
            <SidebarMenuItem v-if="!item.children || !item.children.length">
                <SidebarMenuLink :item="item" />
            </SidebarMenuItem>

            <!-- node with children -->
            <SidebarMenuCollapsible v-else :item="item" />
        </template>
    </ul>
</template>
