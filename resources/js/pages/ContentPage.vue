<script setup lang="ts">
import DocsLayout from '@/components/layout/DocsLayout.vue';
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import Button from '@/components/ui/button/Button.vue';
import { Icon } from '@iconify/vue';
import { Link, usePage } from '@inertiajs/vue3';

defineOptions({
    layout: DocsLayout,
});

interface User {
    id: number;
    name: string;
    username?: string;
    url?: string;
    avatar?: string;
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
    can_edit: boolean;
    edit_url: string | null;
    users: User[];
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
    seo: SeoData;
}>();

// Set page metadata
usePage().props.title = props.page.title;
</script>

<template>
    <SeoHead :seo="seo" />

    <div class="space-y-6">
        <!-- Breadcrumbs -->
        <Breadcrumb v-if="breadcrumbs.length > 1" class="mb-2">
            <BreadcrumbList>
                <template v-for="(breadcrumb, index) in breadcrumbs" :key="index">
                    <BreadcrumbItem>
                        <BreadcrumbLink v-if="index !== breadcrumbs.length - 1" :href="breadcrumb.path" as-child>
                            <Link :href="breadcrumb.path">
                                {{ breadcrumb.title }}
                            </Link>
                        </BreadcrumbLink>
                        <BreadcrumbPage v-else>{{ breadcrumb.title }}</BreadcrumbPage>
                    </BreadcrumbItem>
                    <BreadcrumbSeparator v-if="index < breadcrumbs.length - 1" />
                </template>
            </BreadcrumbList>
        </Breadcrumb>

        <!-- Page title -->
        <div class="flex items-center gap-3">
            <Icon v-if="page.icon" :icon="page.icon" class="!size-8" />
            <h1 class="m-0 text-3xl font-semibold">{{ page.title }}</h1>
        </div>

        <!-- Page Content -->
        <div v-if="hasContent" class="typography">
            <RichContentRenderer :content="page.html_content" />
        </div>

        <!-- Catalog section (always show at end if page has children) -->
        <nav v-if="page.catalog.length > 0" aria-label="الصفحات الفرعية" class="grid grid-cols-[repeat(auto-fill,minmax(min(15rem,100%),1fr))] gap-3">
            <Link
                v-for="child in page.catalog"
                :key="child.id"
                :href="child.slug"
                class="flex min-h-14 items-center gap-3 rounded-lg border border-border bg-card p-4 text-card-foreground no-underline shadow-sm transition-colors hover:border-primary/50 hover:bg-accent"
            >
                <Icon v-if="child.icon" :icon="child.icon" class="!size-5 shrink-0 text-primary" />
                <span class="min-w-0 text-base leading-snug font-medium">{{ child.title }}</span>
            </Link>
        </nav>

        <!-- Authors/Contributors -->
        <div v-if="page.users.length > 0" class="mt-8 flex items-center gap-2 text-sm text-muted-foreground">
            <span>المساهمون:</span>
            <div class="flex flex-wrap gap-2">
                <a
                    v-for="user in page.users"
                    :key="user.id"
                    :href="user.url || '#'"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-primary hover:underline"
                >
                    {{ user.name }}
                </a>
            </div>
        </div>

        <!-- Edit page button (visible only to editors/admins) -->
        <div v-if="page.can_edit && page.edit_url" class="flex justify-end">
            <Button as-child variant="outline" size="lg">
                <a :href="page.edit_url" class="flex items-center gap-2">
                    <Icon icon="lucide:edit" class="!size-4" />
                    تعديل هذه الصفحة
                </a>
            </Button>
        </div>
    </div>
</template>
