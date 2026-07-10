<script setup lang="ts">
import { Breadcrumb, BreadcrumbItem, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import { SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { useColorMode } from '@/composables/useColorMode';
import { usePage } from '@inertiajs/vue3';
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

const activeNavItem = computed(() => manageNavItems.find((item) => isNavItemActive(item, page.url)));

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
    <SidebarProvider :default-open="true">
        <ManageSidebar />
        <div class="max-w-[calc(100dvw)] flex-1 space-y-4 p-2 md:max-w-[calc(100dvw-var(--sidebar-width))]">
            <header class="flex !h-14 w-full items-center justify-between gap-2 rounded-lg border border-sidebar-border bg-sidebar p-2 shadow-sm">
                <div class="flex min-w-0 items-center gap-1">
                    <Button aria-label="فتح القائمة" as-child variant="ghost" size="icon" class="md:hidden">
                        <SidebarTrigger />
                    </Button>
                    <slot name="breadcrumbs">
                        <Breadcrumb class="ms-2">
                            <BreadcrumbList>
                                <BreadcrumbItem>لوحة الإدارة</BreadcrumbItem>
                                <template v-if="activeNavItem">
                                    <BreadcrumbSeparator />
                                    <BreadcrumbItem>
                                        <BreadcrumbPage>{{ activeNavItem.title }}</BreadcrumbPage>
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
            <main class="w-full rounded-lg border border-sidebar-border bg-sidebar p-4 shadow-sm">
                <slot />
            </main>
            <Toaster />
        </div>
    </SidebarProvider>
</template>

<style scoped>
main {
    min-height: calc(100dvh - 5.5rem);
}
</style>
