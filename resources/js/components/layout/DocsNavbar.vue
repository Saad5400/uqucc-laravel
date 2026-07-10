<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useColorMode } from '@/composables/useColorMode';
import { assistant } from '@/routes';
import { Link } from '@inertiajs/vue3';
import { BotMessageSquare, Moon, Sun } from 'lucide-vue-next';
import AiSearchPalette from './AiSearchPalette.vue';
import SearchBar from './SearchBar.vue';

const colorMode = useColorMode();
</script>

<template>
    <header
        class="screenshot-hidden flex !h-14 w-full items-center justify-between gap-2 rounded-lg border border-sidebar-border bg-sidebar p-2 shadow-sm"
    >
        <div class="flex min-w-fit items-center">
            <Button aria-label="فاتح القائمة" as-child variant="ghost" size="icon" class="md:hidden">
                <SidebarTrigger />
            </Button>
            <Link href="/" class="ms-2 flex items-center gap-2">
                <img alt="الشعار" class="size-6" src="/favicon.svg" />
                <span> دليل طالب كلية الحاسبات </span>
            </Link>
        </div>
        <div class="flex items-center gap-2">
            <div>
                <SearchBar />
            </div>

            <AiSearchPalette />

            <Button as-child variant="outline" aria-label="المساعد الذكي" class="gap-2 text-muted-foreground">
                <Link :href="assistant.url()">
                    <BotMessageSquare class="size-4 text-primary" />
                    <span class="hidden md:inline">المساعد الذكي</span>
                </Link>
            </Button>

            <Button
                v-if="colorMode.value === 'dark'"
                aria-label="تبديل إلى الوضع الفاتح"
                @click="colorMode.preference = 'light'"
                variant="ghost"
                size="icon"
            >
                <Moon />
            </Button>
            <Button
                v-if="colorMode.value === 'light'"
                aria-label="تبديل إلى الوضع الداكن"
                @click="colorMode.preference = 'dark'"
                variant="ghost"
                size="icon"
            >
                <Sun />
            </Button>
        </div>
    </header>
</template>
