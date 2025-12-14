<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import DocsLayout from '@/components/layout/DocsLayout.vue';
import {
    Breadcrumb,
    BreadcrumbList,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Card, CardContent } from '@/components/ui/card';

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
        <div v-if="hasContent" class="typography" v-html="page.html_content"></div>

        <!-- Child Pages Grid (for directory pages without content) -->
        <div v-if="!hasContent && page.children.length > 0" class="typography">
            <h1 class="flex items-center gap-2">
                <i v-if="page.icon" :class="page.icon" class="!size-8" />
                {{ breadcrumbs[breadcrumbs.length - 1]?.title }}
            </h1>
            <div class="grid grid-cols-[repeat(auto-fill,minmax(min(20rem,80vw),1fr))] gap-4">
                <Card v-for="child in page.children" :key="child.id">
                    <CardContent class="p-6">
                        <a :href="child.slug" class="flex items-center gap-2 text-lg font-semibold no-underline">
                            <i v-if="child.icon" :class="child.icon" class="!size-6" />
                            {{ child.title }}
                        </a>
                    </CardContent>
                </Card>
            </div>
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
