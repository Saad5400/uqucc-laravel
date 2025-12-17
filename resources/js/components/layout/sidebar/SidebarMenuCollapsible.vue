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
            <div class="flex items-center gap-1">
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton
                        aria-label="فتح/إغلاق القائمة"
                        class="flex size-5 items-center justify-center rounded-sm hover:bg-sidebar-accent"
                    >
                        <ChevronLeft :class="cn('size-4 transition-transform', open && 'rotate-90')" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <SidebarMenuLink :item="item" />
            </div>
        </SidebarMenuItem>

        <CollapsibleContent class="ms-4 border-s border-sidebar-border ps-3">
            <!-- RECURSION happens here -->
            <SidebarMenuList :items="item.children ?? []" />
        </CollapsibleContent>
    </Collapsible>
</template>
