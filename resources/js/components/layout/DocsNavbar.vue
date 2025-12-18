<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Moon, Sun } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Skeleton } from '@/components/ui/skeleton';
import { useColorMode } from '@/composables/useColorMode';

const colorMode = useColorMode();
</script>

<template>
    <header
        class="screenshot-hidden flex items-center justify-between w-full gap-2 p-2 border rounded-lg shadow-sm bg-sidebar border-sidebar-border !h-14"
    >
        <div class="flex items-center">
            <Button aria-label="فاتح القائمة" as-child variant="ghost" size="icon" class="md:hidden">
                <SidebarTrigger />
            </Button>
            <Link href="/" class="flex items-center gap-2 ms-2">
                <img alt="الشعار" class="size-6" src="/favicon.svg" />
                <span> دليل طالب كلية الحاسبات </span>
            </Link>
        </div>
        <div class="flex items-center gap-2">
            <!-- SearchBar placeholder - will be implemented -->
            <!-- <SearchBar /> -->

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
