<script setup lang="ts">
import DocsLayout from '@/components/layout/DocsLayout.vue';
import PagePager from '@/components/page/PagePager.vue';
import TableOfContents from '@/components/page/TableOfContents.vue';
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import Button from '@/components/ui/button/Button.vue';
import { assistant } from '@/routes';
import { Icon } from '@iconify/vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowLeft, BotMessageSquare, Sparkles } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

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
    excerpt?: string | null;
}

interface SiblingLink {
    title: string;
    slug: string;
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
    siblingPrev?: SiblingLink | null;
    siblingNext?: SiblingLink | null;
}>();

// Set page metadata
usePage().props.title = props.page.title;

/** Root page ('/') gets the marketing hero; everything else is a doc page. */
const isRoot = computed(() => props.breadcrumbs.length <= 1 && props.page.slug === '/');
const showToc = computed(() => !isRoot.value && props.hasContent);
/** Contributors page — its bullet list renders as chips (first two = leads, accented). */
const isContributors = computed(() => props.page.slug === '/almsahmon');

/** Hero title "typed like code", once per mount; instant if reduced motion. */
const typedTitle = ref('');
const typingDone = ref(false);
let typeTimer: number | undefined;

onMounted(() => {
    const full = props.page.title;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!isRoot.value || reduce) {
        typedTitle.value = full;
        typingDone.value = true;
        return;
    }

    let i = 0;
    typeTimer = window.setInterval(() => {
        i += 1;
        typedTitle.value = full.slice(0, i);
        if (i >= full.length) {
            window.clearInterval(typeTimer);
            typingDone.value = true;
        }
    }, 45);
});

onUnmounted(() => window.clearInterval(typeTimer));

interface NavItem {
    title: string;
    path: string;
}

/** Secondary feature row under the hero — real destinations only, skipped if absent. */
const features = computed(() => {
    const nav = (usePage().props.navigation || []) as NavItem[];
    const pathFor = (title: string): string | undefined => nav.find((item) => item.title === title)?.path;

    return [
        { title: 'المساعد الذكي', desc: 'اسأل واحصل على إجابة فورية من الدليل.', icon: 'lucide:sparkles', href: assistant.url() },
        { title: 'المساهمون', desc: 'الطلبة الذين ساهموا في بناء الدليل.', icon: 'lucide:heart-handshake', href: pathFor('المساهمون') },
        { title: 'نادي الحاسبات', desc: 'تعرّف على النادي ولجانه وفعالياته.', icon: 'lucide:code-xml', href: pathFor('نادي الحاسبات') },
    ].filter((feature): feature is { title: string; desc: string; icon: string; href: string } => Boolean(feature.href));
});
</script>

<template>
    <SeoHead :seo="seo" />

    <!-- ===== HOME ===== -->
    <div v-if="isRoot">
        <section class="rise mx-auto flex max-w-2xl flex-col items-center gap-5 py-12 text-center md:py-16">
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-primary/20 bg-primary/10 px-3.5 py-1.5 font-heading text-xs font-bold text-primary"
            >
                <Sparkles class="size-3.5" />
                دليل طالب
            </span>
            <h1 class="m-0 font-heading text-4xl leading-[1.15] font-bold tracking-tight md:text-5xl">
                {{ typedTitle }}<span class="type-cursor" :class="{ 'type-cursor--done': typingDone }" aria-hidden="true" />
            </h1>
            <p class="m-0 max-w-md text-base leading-relaxed text-muted-foreground md:text-lg">{{ seo.description }}</p>
            <div class="mt-1 flex flex-wrap justify-center gap-3">
                <Button as-child size="lg">
                    <a href="#grid" class="flex items-center gap-2">
                        تصفّح الدليل
                        <ArrowDown class="size-4" />
                    </a>
                </Button>
                <Button as-child size="lg" variant="outline">
                    <Link :href="assistant.url()" class="flex items-center gap-2">
                        <BotMessageSquare class="size-4" />
                        اسأل المساعد الذكي
                    </Link>
                </Button>
            </div>
        </section>

        <!-- Feature row (secondary links under the hero) -->
        <div v-if="features.length > 0" class="mb-8 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Link
                v-for="feature in features"
                :key="feature.title"
                :href="feature.href"
                class="group flex items-center gap-3 rounded-xl border border-border bg-card p-4 no-underline transition-colors hover:border-primary/50 hover:bg-accent/40"
            >
                <span class="flex size-10 shrink-0 items-center justify-center rounded-[10px] bg-primary/10 text-primary">
                    <Icon :icon="feature.icon" class="!size-5" />
                </span>
                <span class="flex min-w-0 flex-col">
                    <span class="font-heading font-bold text-foreground">{{ feature.title }}</span>
                    <span class="text-xs leading-relaxed text-muted-foreground">{{ feature.desc }}</span>
                </span>
            </Link>
        </div>

        <div v-if="page.catalog.length > 0" id="grid" class="grid scroll-mt-20 grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="(child, index) in page.catalog"
                :key="child.id"
                :href="child.slug"
                class="rise group flex flex-col gap-2.5 rounded-2xl border border-border bg-card p-6 text-card-foreground no-underline transition-[transform,border-color,background-color] duration-200 hover:-translate-y-1 hover:border-primary/50 hover:bg-accent/40"
                :style="{ animationDelay: `${index * 60}ms` }"
            >
                <span class="flex size-11 items-center justify-center rounded-[10px] bg-primary/10 text-primary">
                    <Icon :icon="child.icon || 'lucide:folder'" class="!size-6" />
                </span>
                <span class="font-heading text-lg leading-snug font-bold text-foreground">{{ child.title }}</span>
                <span v-if="child.excerpt" class="flex-1 text-sm leading-relaxed text-muted-foreground">{{ child.excerpt }}</span>
                <span class="mt-1.5 flex items-center gap-1.5 text-sm font-semibold text-primary">
                    تصفّح القسم
                    <ArrowLeft class="size-3.5" />
                </span>
            </Link>
        </div>
    </div>

    <!-- ===== DOC PAGE ===== -->
    <div v-else class="flex gap-10">
        <div class="min-w-0 flex-1 space-y-6">
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
                <Icon v-if="page.icon" :icon="page.icon" class="!size-8 shrink-0 text-primary" />
                <h1 class="m-0 font-heading text-3xl leading-tight font-bold tracking-tight md:text-[2.15rem]">{{ page.title }}</h1>
            </div>

            <!-- Page Content -->
            <div v-if="hasContent" class="typography max-w-[680px]" :class="{ contributors: isContributors }">
                <RichContentRenderer :content="page.html_content" />
            </div>

            <!-- Catalog section (children as cards) -->
            <nav v-if="page.catalog.length > 0" aria-label="الصفحات الفرعية" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Link
                    v-for="(child, index) in page.catalog"
                    :key="child.id"
                    :href="child.slug"
                    class="rise group flex flex-col gap-2.5 rounded-2xl border border-border bg-card p-6 text-card-foreground no-underline transition-[transform,border-color,background-color] duration-200 hover:-translate-y-1 hover:border-primary/50 hover:bg-accent/40"
                    :style="{ animationDelay: `${index * 60}ms` }"
                >
                    <span class="flex size-11 items-center justify-center rounded-[10px] bg-primary/10 text-primary">
                        <Icon :icon="child.icon || 'lucide:folder'" class="!size-6" />
                    </span>
                    <span class="font-heading text-lg leading-snug font-bold text-foreground">{{ child.title }}</span>
                    <span v-if="child.excerpt" class="flex-1 text-sm leading-relaxed text-muted-foreground">{{ child.excerpt }}</span>
                    <span class="mt-1.5 flex items-center gap-1.5 text-sm font-semibold text-primary">
                        تصفّح القسم
                        <ArrowLeft class="size-3.5" />
                    </span>
                </Link>
            </nav>

            <!-- Prev/Next pager -->
            <PagePager :prev="siblingPrev" :next="siblingNext" />

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

        <!-- Table of contents -->
        <TableOfContents v-if="showToc" />
    </div>
</template>

<style scoped>
@keyframes riseIn {
    from {
        opacity: 0;
        transform: translateY(14px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.rise {
    animation: riseIn 0.45s ease-out both;
}

/* Blinking terminal-style cursor at the end of the typing hero title. */
.type-cursor {
    display: inline-block;
    inline-size: 0.07em;
    block-size: 0.95em;
    margin-inline-start: 0.08em;
    vertical-align: -0.08em;
    background: currentColor;
    animation: blink 1s step-end infinite;
}

.type-cursor--done {
    animation-duration: 1.6s;
}

@keyframes blink {
    50% {
        opacity: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .rise {
        animation: none;
    }

    .type-cursor {
        animation: none;
    }
}
</style>
