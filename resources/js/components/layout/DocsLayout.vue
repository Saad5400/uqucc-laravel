<script setup lang="ts">
import { SidebarProvider } from '@/components/ui/sidebar';
import { useColorMode } from '@/composables/useColorMode';
import { computed, onMounted } from 'vue';
import { Toaster, toast } from 'vue-sonner';
import 'vue-sonner/style.css';
import DocsNavbar from './DocsNavbar.vue';
import DocsSidebar from './DocsSidebar.vue';

const { isDark } = useColorMode();
const toasterTheme = computed(() => (isDark.value ? 'dark' : 'light'));

const FRIDAY_GREETING_KEY = 'friday-greeting-shown';

/** Friday greeting — at most once per browsing session, not on every page load. */
onMounted(() => {
    if (new Date().getDay() === 5 && !sessionStorage.getItem(FRIDAY_GREETING_KEY)) {
        sessionStorage.setItem(FRIDAY_GREETING_KEY, '1');
        setTimeout(() => toast('اللهم صل وسلم على نبينا محمد'), 1500);
    }
});
</script>

<template>
    <SidebarProvider :default-open="true">
        <DocsSidebar />
        <div class="max-w-[calc(100dvw)] min-w-0 flex-1 space-y-4 p-2 md:max-w-[calc(100dvw-var(--sidebar-width))]">
            <DocsNavbar />
            <main class="w-full rounded-lg border border-sidebar-border bg-sidebar p-4 shadow-sm">
                <slot />
                <Toaster
                    class="screenshot-hidden"
                    :theme="toasterTheme"
                    dir="rtl"
                    position="bottom-left"
                    :toast-options="{
                        style: {
                            background: 'var(--popover)',
                            color: 'var(--popover-foreground)',
                            borderColor: 'var(--border)',
                        },
                    }"
                />
            </main>
        </div>
    </SidebarProvider>
</template>

<style scoped>
main {
    min-height: calc(100dvh - 5.5rem);
}
</style>
