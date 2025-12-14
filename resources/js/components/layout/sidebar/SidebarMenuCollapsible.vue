<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { SidebarMenuItem, SidebarMenuButton } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import SidebarMenuList from './SidebarMenuList.vue';
import SidebarMenuLink from './SidebarMenuLink.vue';

interface NavigationItem {
    id: number;
    title: string;
    path: string;
    icon?: string;
    children?: NavigationItem[];
}

const props = defineProps<{
    item: NavigationItem;
}>();

const page = usePage();
const open = ref(false);

const currentPath = computed(() => page.url);

watch(
    currentPath,
    (newPath) => {
        open.value = newPath.includes(props.item.path);
    },
    { immediate: true }
);
</script>

<template>
    <Collapsible v-model:open="open">
        <SidebarMenuItem>
            <div class="flex items-center justify-between gap-1">
                <SidebarMenuLink :item="item" />
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton
                        aria-label="فتح/إغلاق القائمة"
                        :class="cn('flex items-center justify-center w-fit', open && 'bg-sidebar-accent')"
                    >
                        <ChevronLeft :class="cn('transition-transform size-4', open && '-rotate-90')" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
            </div>
        </SidebarMenuItem>

        <CollapsibleContent class="my-2 ps-2 ms-2 border-s-1 border-foreground/30">
            <!-- RECURSION happens here -->
            <SidebarMenuList :items="item.children ?? []" />
        </CollapsibleContent>
    </Collapsible>
</template>
