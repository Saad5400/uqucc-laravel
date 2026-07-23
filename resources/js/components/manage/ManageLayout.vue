<script setup lang="ts">
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import { SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { useColorMode } from '@/composables/useColorMode';
import { Link, usePage } from '@inertiajs/vue3';
import { Moon, Sun } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import { toast, Toaster } from 'vue-sonner';
import 'vue-sonner/style.css';
import ManageSidebar from './ManageSidebar.vue';
import { isNavItemActive, manageNavItems } from './nav';

interface ManageFlash {
    success: string | null;
    error: string | null;
}

const page = usePage();
const { isDark } = useColorMode();
const toasterTheme = computed(() => (isDark.value ? 'dark' : 'light'));

const activeNavItem = computed(() => manageNavItems.find((item) => isNavItemActive(item, page.url)));

/** The dashboard is the breadcrumb root itself, so it gets no second crumb. */
const sectionNavItem = computed(() => (activeNavItem.value && activeNavItem.value.href !== '/manage' ? activeNavItem.value : null));

/**
 * Toast bridge: the backend flashes success/error messages into the shared
 * `flash` prop. Inertia replaces the props object on every response, so this
 * watcher fires once per navigation; it only toasts when a message is present,
 * and the session flash is consumed server-side after one request, so the same
 * message never double-fires.
 */
watch(
    () => page.props.flash as ManageFlash | undefined,
    (flash) => {
        if (flash?.success) {
            toast.success(flash.success);
        }

        if (flash?.error) {
            toast.error(flash.error);
        }
    },
);
</script>

<template>
    <SidebarProvider :default-open="true" style="--sidebar-width: 16rem">
        <ManageSidebar />
        <div class="min-w-0 flex-1 space-y-4 p-2">
            <header
                class="sticky top-2 z-20 flex !h-14 w-full items-center justify-between gap-2 rounded-lg border border-sidebar-border bg-sidebar p-2 shadow-sm"
            >
                <div class="flex min-w-0 items-center gap-1">
                    <Button aria-label="طي القائمة الجانبية أو فتحها" as-child variant="ghost" size="icon">
                        <SidebarTrigger />
                    </Button>
                    <slot name="breadcrumbs">
                        <Breadcrumb class="ms-2">
                            <BreadcrumbList>
                                <BreadcrumbItem>
                                    <BreadcrumbPage v-if="!sectionNavItem">لوحة الإدارة</BreadcrumbPage>
                                    <BreadcrumbLink v-else as-child>
                                        <Link href="/manage">لوحة الإدارة</Link>
                                    </BreadcrumbLink>
                                </BreadcrumbItem>
                                <template v-if="sectionNavItem">
                                    <BreadcrumbSeparator />
                                    <BreadcrumbItem>
                                        <BreadcrumbPage>{{ sectionNavItem.title }}</BreadcrumbPage>
                                    </BreadcrumbItem>
                                </template>
                            </BreadcrumbList>
                        </Breadcrumb>
                    </slot>
                </div>
                <Button v-if="isDark" aria-label="تبديل إلى الوضع الفاتح" variant="ghost" size="icon" @click="isDark = false">
                    <Moon />
                </Button>
                <Button v-else aria-label="تبديل إلى الوضع الداكن" variant="ghost" size="icon" @click="isDark = true">
                    <Sun />
                </Button>
            </header>
            <main class="w-full rounded-lg border border-sidebar-border bg-sidebar p-2 shadow-sm sm:p-4">
                <slot />
            </main>
            <Toaster :theme="toasterTheme" dir="rtl" position="bottom-left" />
        </div>
    </SidebarProvider>
</template>

<style scoped>
main {
    min-height: calc(100dvh - 5.5rem);
}
</style>
