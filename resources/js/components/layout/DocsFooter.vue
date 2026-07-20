<script setup lang="ts">
import { Icon } from '@iconify/vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface NavigationItem {
    id: number;
    title: string;
    path: string;
    icon?: string;
}

const page = usePage();
const sections = computed(() => ((page.props.navigation || []) as NavigationItem[]).slice(0, 6));
const year = new Date().getFullYear();
</script>

<template>
    <footer class="mt-4 rounded-lg border border-sidebar-border bg-sidebar p-6 text-sm shadow-sm md:p-8">
        <div class="grid gap-8 md:grid-cols-[1.5fr_1fr_1fr]">
            <!-- Brand -->
            <div class="flex flex-col gap-3">
                <Link href="/" class="flex items-center gap-2 font-semibold">
                    <img alt="الشعار" class="size-6 shrink-0" src="/favicon.svg" />
                    دليل طالب كلية الحاسبات
                </Link>
                <p class="max-w-[40ch] leading-relaxed text-muted-foreground">هذا الموقع هو دليل غير رسمي مُقدم من طلاب كلية الحاسبات إلى الطلاب</p>
            </div>

            <!-- Sections -->
            <nav aria-label="أقسام الدليل" class="flex flex-col gap-3">
                <h2 class="font-semibold text-foreground">أقسام الدليل</h2>
                <ul class="flex flex-col gap-2">
                    <li v-for="section in sections" :key="section.id">
                        <Link :href="section.path" class="text-muted-foreground transition-colors hover:text-primary">
                            {{ section.title }}
                        </Link>
                    </li>
                </ul>
            </nav>

            <!-- Quick links -->
            <nav aria-label="روابط سريعة" class="flex flex-col gap-3">
                <h2 class="font-semibold text-foreground">روابط</h2>
                <ul class="flex flex-col gap-2">
                    <li>
                        <Link href="/almosaed" class="flex items-center gap-2 text-muted-foreground transition-colors hover:text-primary">
                            <Icon icon="lucide:sparkles" class="!size-4" />
                            المساعد الذكي
                        </Link>
                    </li>
                    <li>
                        <Link href="/almsahmon" class="flex items-center gap-2 text-muted-foreground transition-colors hover:text-primary">
                            <Icon icon="lucide:heart-handshake" class="!size-4" />
                            المساهمون
                        </Link>
                    </li>
                </ul>
            </nav>
        </div>

        <div
            class="mt-8 flex flex-col gap-2 border-t border-sidebar-border pt-6 text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
        >
            <span>© {{ year }} طلاب كلية الحاسبات — جامعة أم القرى</span>
            <span class="text-xs">صُنع بحب لطلبة كلية الحاسبات</span>
        </div>
    </footer>
</template>
