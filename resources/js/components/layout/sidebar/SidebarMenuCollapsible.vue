<script setup lang="ts">
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { SidebarMenuButton } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import SidebarMenuLink from './SidebarMenuLink.vue';
import SidebarMenuList from './SidebarMenuList.vue';

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
    { immediate: true },
);
</script>

<template>
    <!-- Use as="li" so the Collapsible renders a <li> element directly, 
         making valid HTML: <ul><li (Collapsible)>...</li></ul> -->
    <Collapsible v-model:open="open" as="li" class="group/menu-item relative" data-sidebar="menu-item">
        <div class="flex items-center justify-between gap-1">
            <SidebarMenuLink :item="item" />
            <CollapsibleTrigger asChild>
                <SidebarMenuButton aria-label="فتح/إغلاق القائمة" :class="cn('flex w-fit items-center justify-center', open && 'bg-sidebar-accent')">
                    <ChevronLeft :class="cn('size-4 transition-transform', open && '-rotate-90')" />
                </SidebarMenuButton>
            </CollapsibleTrigger>
        </div>

        <CollapsibleContent class="my-2 ms-2 border-s-1 border-foreground/10 ps-2">
            <!-- *** RECURSION happens here *** -->
            <SidebarMenuList :items="item.children ?? []" />
        </CollapsibleContent>
    </Collapsible>
</template>
