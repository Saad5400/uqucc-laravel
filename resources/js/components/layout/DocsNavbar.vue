<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useColorMode } from '@/composables/useColorMode';
import { assistant } from '@/routes';
import { Link } from '@inertiajs/vue3';
import { BotMessageSquare, Moon, Sun } from 'lucide-vue-next';
import { onMounted, onUnmounted, ref } from 'vue';
import AiSearchPalette from './AiSearchPalette.vue';

const { isDark, toggle } = useColorMode();

/** Navbar gains a border + shadow only once the page has scrolled a little. */
const scrolled = ref(false);
const onScroll = (): void => {
    scrolled.value = window.scrollY > 8;
};

onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
});
onUnmounted(() => window.removeEventListener('scroll', onScroll));
</script>

<template>
    <header
        :class="[
            'screenshot-hidden sticky top-2 z-30 flex !h-14 w-full items-center justify-between gap-2 rounded-xl bg-sidebar/75 p-2 backdrop-blur-md transition-[box-shadow,border-color] duration-200 supports-[backdrop-filter]:bg-sidebar/60',
            scrolled ? 'border border-sidebar-border shadow-md' : 'border border-transparent shadow-none',
        ]"
    >
        <div class="flex min-w-0 flex-1 items-center">
            <Button aria-label="فاتح القائمة" as-child variant="ghost" size="icon" class="shrink-0 md:hidden">
                <SidebarTrigger />
            </Button>
            <Link href="/" class="ms-2 flex min-w-0 items-center gap-2">
                <img alt="الشعار" class="size-6 shrink-0" src="/favicon.svg" />
                <span class="truncate">دليل طالب كلية الحاسبات</span>
            </Link>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <AiSearchPalette />

            <Button as-child variant="outline" aria-label="المساعد الذكي" class="gap-2 border-primary/30 bg-primary/5 hover:bg-primary/10">
                <Link :href="assistant.url()">
                    <BotMessageSquare class="size-4 text-primary" />
                    <span class="hidden lg:inline">المساعد الذكي</span>
                </Link>
            </Button>

            <Button
                :aria-label="isDark ? 'التبديل إلى الوضع الفاتح' : 'التبديل إلى الوضع الداكن'"
                variant="ghost"
                size="icon"
                class="shrink-0"
                @click="toggle()"
            >
                <Sun v-if="isDark" />
                <Moon v-else />
            </Button>
        </div>
    </header>
</template>
