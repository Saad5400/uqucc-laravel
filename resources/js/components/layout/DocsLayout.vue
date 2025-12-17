<script setup lang="ts">
import { onMounted } from 'vue';
import { SidebarProvider } from '@/components/ui/sidebar';
import { Toaster, toast } from 'vue-sonner';
import 'vue-sonner/style.css';
import DocsSidebar from './DocsSidebar.vue';
import DocsNavbar from './DocsNavbar.vue';

// Show Friday greeting
onMounted(async () => {
    if (new Date().getDay() === 5) {
        setTimeout(() => toast.info('اللهم صل وسلم على نبينا محمد'), 100);
    }
});
</script>

<template>
    <SidebarProvider :default-open="true">
        <DocsSidebar />
        <div class="flex-1 p-2 space-y-4 max-w-[calc(100dvw)] md:max-w-[calc(100dvw-var(--sidebar-width))]">
            <DocsNavbar />
            <main class="w-full p-4 border rounded-lg shadow-sm bg-sidebar border-sidebar-border">
                <slot />
                <Toaster class="screenshot-hidden" />
            </main>
        </div>
    </SidebarProvider>
</template>

<style scoped>
main {
    min-height: calc(100dvh - 5.5rem);
}
</style>
