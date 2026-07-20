<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

interface TocEntry {
    id: string;
    text: string;
    level: number;
}

const entries = ref<TocEntry[]>([]);
const activeId = ref<string>('');
let observer: MutationObserver | null = null;
let io: IntersectionObserver | null = null;

/** Scan the rendered prose, assign stable ids to headings, build the ToC. */
function scan(): void {
    const root = document.querySelector('.typography');
    if (!root) {
        return;
    }

    const headings = Array.from(root.querySelectorAll('h2, h3')) as HTMLElement[];
    if (headings.length === 0) {
        return;
    }

    entries.value = headings.map((heading, index) => {
        if (!heading.id) {
            heading.id = `heading-${index + 1}`;
        }
        heading.style.scrollMarginTop = '84px';
        return { id: heading.id, text: heading.textContent?.trim() ?? '', level: heading.tagName === 'H3' ? 3 : 2 };
    });

    io?.disconnect();
    io = new IntersectionObserver(
        (records) => {
            for (const record of records) {
                if (record.isIntersecting) {
                    activeId.value = record.target.id;
                }
            }
        },
        { rootMargin: '-80px 0px -70% 0px' },
    );
    headings.forEach((heading) => io?.observe(heading));
}

onMounted(() => {
    scan();
    // Prose is rendered by the TipTap editor after mount — watch for it.
    observer = new MutationObserver(() => scan());
    const root = document.querySelector('.typography');
    if (root) {
        observer.observe(root, { childList: true, subtree: true });
    }
});

onBeforeUnmount(() => {
    observer?.disconnect();
    io?.disconnect();
});
</script>

<template>
    <aside
        v-if="entries.length >= 2"
        class="toc-enter sticky top-[84px] hidden max-h-[calc(100dvh-96px)] w-56 shrink-0 overflow-y-auto rounded-xl border border-border bg-card/40 p-4 lg:block"
    >
        <p class="mb-3 text-center text-sm font-bold text-foreground">في هذه الصفحة</p>
        <nav>
            <ul class="flex flex-col gap-1 border-s border-foreground/10 ps-3 text-sm">
                <li v-for="entry in entries" :key="entry.id" :class="entry.level === 3 ? 'ps-3' : ''">
                    <a
                        :href="`#${entry.id}`"
                        class="block py-1 leading-relaxed transition-colors"
                        :class="activeId === entry.id ? 'font-semibold text-primary' : 'text-muted-foreground hover:text-foreground'"
                    >
                        {{ entry.text }}
                    </a>
                </li>
            </ul>
        </nav>
    </aside>
</template>

<style scoped>
@keyframes tocIn {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.toc-enter {
    animation: tocIn 0.24s ease-out both;
}

@media (prefers-reduced-motion: reduce) {
    .toc-enter {
        animation: none;
    }
}
</style>
