<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import DocsLayout from '@/components/layout/DocsLayout.vue';
import {
    Breadcrumb,
    BreadcrumbList,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';

defineOptions({
    layout: DocsLayout,
});

interface Author {
    id: number;
    name: string;
    username: string;
    url?: string;
}

interface ChildPage {
    id: number;
    slug: string;
    title: string;
    icon?: string;
}

interface PageData {
    id: number;
    slug: string;
    title: string;
    description?: string;
    html_content: string;
    icon?: string;
    og_image?: string;
    authors: Author[];
    children: ChildPage[];
}

interface Breadcrumb {
    title: string;
    path: string;
}

const props = defineProps<{
    page: PageData;
    breadcrumbs: Breadcrumb[];
    hasContent: boolean;
}>();

// Set page metadata
usePage().props.title = props.page.title;
</script>

<template>
    <div class="container mx-auto max-w-5xl py-8 px-4">
        <!-- Breadcrumbs -->
        <Breadcrumb v-if="breadcrumbs.length > 1" class="mb-4">
            <BreadcrumbList>
                <template v-for="(breadcrumb, index) in breadcrumbs" :key="index">
                    <BreadcrumbItem>
                        <BreadcrumbLink v-if="index !== breadcrumbs.length - 1" :href="breadcrumb.path">
                            {{ breadcrumb.title }}
                        </BreadcrumbLink>
                    </BreadcrumbItem>
                    <BreadcrumbSeparator v-if="index < breadcrumbs.length - 2" />
                </template>
            </BreadcrumbList>
        </Breadcrumb>

        <!-- Page Content -->
        <div class="typography">
            <!-- Main content -->
            <div v-if="hasContent" v-html="page.html_content"></div>

            <!-- Page heading if no content -->
            <h1 v-else class="flex items-center gap-2">
                <i v-if="page.icon" :class="page.icon" class="!size-8" />
                {{ breadcrumbs[breadcrumbs.length - 1]?.title }}
            </h1>

            <!-- Catalog section (always show at end if page has children) -->
            <template v-if="page.children.length > 0">
                <h3>في هذا القسم</h3>
                <div class="grid grid-cols-[repeat(auto-fill,minmax(min(14rem,80vw),1fr))] gap-2 mb-8">
                    <a
                        v-for="child in page.children"
                        :key="child.id"
                        :href="child.slug"
                        class="inline-flex items-center gap-2 rounded-md font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/80 has-[>svg]:px-3 p-8 whitespace-normal size-full flex justify-start text-start px-4 py-2 text-lg no-underline"
                    >
                        <i v-if="child.icon" :class="child.icon" class="!size-8 me-1" />
                        {{ child.title }}
                    </a>
                </div>
            </template>
        </div>

        <!-- Authors -->
        <div v-if="page.authors.length > 0" class="mt-8 flex gap-2 items-center text-sm text-muted-foreground">
            <span>المساهمون:</span>
            <div class="flex gap-2 flex-wrap">
                <a
                    v-for="author in page.authors"
                    :key="author.id"
                    :href="author.url || '#'"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-primary hover:underline"
                >
                    {{ author.name }}
                </a>
            </div>
        </div>
    </div>
</template>
