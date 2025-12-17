<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import {
    Sidebar,
    SidebarHeader,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarMenu,
} from '@/components/ui/sidebar';
import SidebarMenuList from './sidebar/SidebarMenuList.vue';

interface NavigationItem {
    id: number;
    title: string;
    path: string;
    icon?: string;
    children?: NavigationItem[];
}

const page = usePage();
const navigation = computed(() => (page.props.navigation || []) as NavigationItem[]);
</script>

<template>
    <Sidebar side="right" variant="floating">
        <SidebarHeader />
        <SidebarContent style="scrollbar-gutter: stable">
            <SidebarGroup>
                <SidebarMenu>
                    <SidebarMenuList :items="navigation" />
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>
        <SidebarFooter />
    </Sidebar>
</template>
