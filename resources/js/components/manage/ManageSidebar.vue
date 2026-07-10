<script setup lang="ts">
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronsUpDown, Globe, LogOut } from 'lucide-vue-next';
import { computed } from 'vue';
import { isNavItemActive, manageNavItems } from './nav';

interface ManageUser {
    id: number;
    name: string;
    email: string;
    roles: string[];
    permissions: string[];
}

const page = usePage();
const sidebar = useSidebar();

const user = computed(() => (page.props.auth?.user ?? null) as unknown as ManageUser | null);

const roleLabels: Record<string, string> = {
    admin: 'مدير',
    editor: 'محرر',
};

const userRoles = computed(() => (user.value?.roles ?? []).map((role) => roleLabels[role] ?? role).join('، '));
</script>

<template>
    <Sidebar side="right" variant="floating">
        <SidebarHeader>
            <Link href="/manage" class="flex items-center gap-2 p-2">
                <img alt="الشعار" class="size-6" src="/favicon.svg" />
                <span class="font-semibold">لوحة الإدارة</span>
            </Link>
        </SidebarHeader>
        <SidebarContent style="scrollbar-gutter: stable">
            <SidebarGroup>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in manageNavItems" :key="item.href">
                        <SidebarMenuButton class="text-start" as-child :is-active="isNavItemActive(item, page.url)">
                            <Link :href="item.href" @click="sidebar.setOpenMobile(false)">
                                <component :is="item.icon" class="!size-5" />
                                {{ item.title }}
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>
        <SidebarFooter>
            <SidebarMenu v-if="user">
                <SidebarMenuItem>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <SidebarMenuButton size="lg" class="text-start">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary text-sm font-semibold text-primary-foreground"
                                >
                                    {{ user.name.charAt(0) }}
                                </div>
                                <div class="flex min-w-0 flex-1 flex-col">
                                    <span class="truncate text-sm font-medium">{{ user.name }}</span>
                                    <span class="truncate text-xs text-muted-foreground">{{ userRoles }}</span>
                                </div>
                                <ChevronsUpDown class="ms-auto size-4" />
                            </SidebarMenuButton>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent side="top" align="start" class="w-(--reka-dropdown-menu-trigger-width)">
                            <DropdownMenuLabel class="font-normal">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium">{{ user.name }}</span>
                                    <span class="text-xs text-muted-foreground" dir="ltr">{{ user.email }}</span>
                                </div>
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem as-child>
                                <Link href="/" class="w-full cursor-pointer">
                                    <Globe />
                                    الموقع العام
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem as-child variant="destructive">
                                <Link href="/manage/logout" method="post" as="button" class="w-full cursor-pointer">
                                    <LogOut />
                                    تسجيل الخروج
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarFooter>
    </Sidebar>
</template>
