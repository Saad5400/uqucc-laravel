<script setup lang="ts">
import { Button } from '@/components/ui/button';
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
import { ChevronsUpDown, Globe, LogOut, X } from 'lucide-vue-next';
import { computed } from 'vue';
import { isNavItemActive, visibleNavItems } from './nav';

interface ManageUser {
    id: number;
    name: string;
    email: string;
    roles: string[];
    permissions: string[];
    can_review_changes: boolean;
}

const page = usePage();
const sidebar = useSidebar();

const user = computed(() => (page.props.auth?.user ?? null) as unknown as ManageUser | null);
const pendingReviewsCount = computed(() => (page.props.pendingReviewsCount as number | undefined) ?? 0);

const roleLabels: Record<string, string> = {
    admin: 'مدير',
    editor: 'محرر',
};

const userRoles = computed(() => (user.value?.roles ?? []).map((role) => roleLabels[role] ?? role).join('، '));

const navItems = computed(() => visibleNavItems(user.value?.permissions ?? [], user.value?.can_review_changes ?? false));
</script>

<template>
    <Sidebar side="right" variant="floating" collapsible="icon">
        <SidebarHeader>
            <div class="flex items-center justify-between gap-1">
                <Link
                    href="/manage"
                    class="flex min-w-0 items-center gap-2 p-2 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-1"
                >
                    <img alt="الشعار" class="size-6 shrink-0" src="/favicon.svg" />
                    <span class="truncate font-semibold group-data-[collapsible=icon]:hidden">لوحة الإدارة</span>
                </Link>
                <Button v-if="sidebar.isMobile.value" variant="ghost" size="icon" aria-label="إغلاق القائمة" @click="sidebar.setOpenMobile(false)">
                    <X />
                </Button>
            </div>
        </SidebarHeader>
        <SidebarContent style="scrollbar-gutter: stable">
            <SidebarGroup>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in navItems" :key="item.href">
                        <SidebarMenuButton class="text-start" as-child :is-active="isNavItemActive(item, page.url)" :tooltip="item.title">
                            <Link :href="item.href" @click="sidebar.setOpenMobile(false)">
                                <component :is="item.icon" class="!size-5 group-data-[collapsible=icon]:!size-4" />
                                <span class="truncate">{{ item.title }}</span>
                                <span
                                    v-if="item.href === '/manage/reviews' && pendingReviewsCount > 0"
                                    class="ms-auto inline-flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-medium text-primary-foreground tabular-nums group-data-[collapsible=icon]:hidden"
                                    :aria-label="`${pendingReviewsCount} تعديل بانتظار المراجعة`"
                                >
                                    {{ pendingReviewsCount }}
                                </span>
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
                        <DropdownMenuContent side="top" align="start" class="w-(--reka-dropdown-menu-trigger-width) min-w-56">
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
