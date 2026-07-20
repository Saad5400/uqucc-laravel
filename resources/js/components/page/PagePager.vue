<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight } from 'lucide-vue-next';

interface SiblingLink {
    title: string;
    slug: string;
}

defineProps<{
    prev?: SiblingLink | null;
    next?: SiblingLink | null;
}>();
</script>

<template>
    <nav v-if="prev || next" aria-label="تنقّل بين الصفحات" class="mt-10 grid grid-cols-1 gap-3 border-t border-border pt-6 sm:grid-cols-2">
        <!-- Previous: further right in RTL reading order → arrow points right -->
        <Link
            v-if="prev"
            :href="prev.slug"
            class="group flex items-center gap-3 rounded-xl border border-border bg-card p-4 no-underline transition-colors hover:border-primary/50 hover:bg-accent/40"
        >
            <ArrowRight class="size-5 shrink-0 text-muted-foreground transition-colors group-hover:text-primary" />
            <span class="flex min-w-0 flex-col">
                <span class="text-xs text-muted-foreground">السابق</span>
                <span class="truncate font-semibold text-foreground">{{ prev.title }}</span>
            </span>
        </Link>
        <span v-else class="hidden sm:block" />

        <!-- Next: further left in RTL → arrow points left -->
        <Link
            v-if="next"
            :href="next.slug"
            class="group flex items-center justify-end gap-3 rounded-xl border border-border bg-card p-4 text-end no-underline transition-colors hover:border-primary/50 hover:bg-accent/40"
        >
            <span class="flex min-w-0 flex-col">
                <span class="text-xs text-muted-foreground">التالي</span>
                <span class="truncate font-semibold text-foreground">{{ next.title }}</span>
            </span>
            <ArrowLeft class="size-5 shrink-0 text-muted-foreground transition-colors group-hover:text-primary" />
        </Link>
    </nav>
</template>
