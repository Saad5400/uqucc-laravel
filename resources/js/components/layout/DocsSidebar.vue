<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import {
    Sidebar,
    SidebarHeader,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
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
    <Sidebar side="right" variant="sidebar" class="border-l">
        <SidebarHeader class="border-b">
            <div class="flex items-center justify-center py-4">
                <h2 class="text-lg font-semibold">القائمة</h2>
            </div>
        </SidebarHeader>
        <SidebarContent class="px-2">
            <SidebarGroup>
                <SidebarGroupLabel>المحتوى</SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuList :items="navigation" />
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>
        <SidebarFooter class="border-t p-4">
            <div class="text-sm text-neutral-500 text-center">
                نادي الحاسبات - جامعة القصيم
            </div>
        </SidebarFooter>
    </Sidebar>
</template>
