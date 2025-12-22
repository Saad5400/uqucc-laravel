<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Icon } from '@iconify/vue';
import DocsLayout from '@/components/layout/DocsLayout.vue';
import {
    Breadcrumb,
    BreadcrumbList,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import Button from '@/components/ui/button/Button.vue';
import RichContentRenderer from '@/components/RichContentRenderer.vue';

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
    html_content: string | Record<string, unknown> | null;
    icon?: string;
    authors: Author[];
    children: ChildPage[];
    catalog: ChildPage[];
    quick_response?: {
        enabled: boolean;
        send_link: boolean;
        message?: string | null;
        buttons: { text: string; url: string }[];
        attachments: { name: string; url: string }[];
    };
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
    <div class="space-y-6">
        <!-- Breadcrumbs -->
        <Breadcrumb v-if="breadcrumbs.length > 1" class="mb-2">
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

        <!-- Page title -->
        <div class="flex items-center gap-3">
            <Icon v-if="page.icon" :icon="page.icon" class="!size-8" />
            <h1 class="text-3xl font-semibold m-0">{{ page.title }}</h1>
        </div>

        <!-- Page Content -->
        <div class="typography">
            <!-- Main content -->
            <RichContentRenderer v-if="hasContent" :content="page.html_content" />

            <!-- Page heading if no content -->
            <h1 v-else class="flex items-center gap-2">
                <i v-if="page.icon" :class="page.icon" class="!size-8" />
                {{ breadcrumbs[breadcrumbs.length - 1]?.title }}
            </h1>

            <!-- Catalog section (always show at end if page has children) -->
            <template v-if="page.catalog.length > 0">
                <div class="grid grid-cols-[repeat(auto-fill,minmax(min(20rem,80dvw),1fr))] gap-4">
                    <Button
                        v-for="child in page.catalog"
                        :key="child.id"
                        as-child
                        variant="secondary"
                        class="p-8 text-2xl whitespace-normal size-full flex justify-start text-start"
                    >
                        <a :href="child.slug" class="flex items-center gap-2 w-full no-underline text-current">
                            <Icon v-if="child.icon" :icon="child.icon" class="!size-8 me-1" />
                            {{ child.title }}
                        </a>
                    </Button>
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
