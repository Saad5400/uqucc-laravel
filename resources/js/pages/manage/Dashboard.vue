<script setup lang="ts">
import LineChart, { type LineChartPoint } from '@/components/manage/charts/LineChart.vue';
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { formatNumber, formatRelativeTime, formatShortDate } from '@/lib/formatters';
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { Eraser } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

interface DashboardStats {
    totalPages: number;
    rootPages: number;
    contributors: number;
    views30d: number;
    uniqueVisitors30d: number;
    botUses30d: number;
    topCommand: { name: string; uses: number } | null;
}

interface ChartPoint {
    date: string;
    count: number;
}

const props = defineProps<{
    stats: DashboardStats;
    viewsChart?: ChartPoint[];
    commandsChart?: ChartPoint[];
    latestPages?: { id: number; title: string; updated_at: string | null }[];
    mostViewed?: { id: number; title: string; views: number }[];
    topCommands?: { command: string; uses: number }[];
}>();

const tiles = computed(() => [
    {
        label: 'إجمالي الصفحات',
        value: formatNumber(props.stats.totalPages),
        sub: `منها ${formatNumber(props.stats.rootPages)} صفحات رئيسية`,
    },
    {
        label: 'المساهمون',
        value: formatNumber(props.stats.contributors),
        sub: 'مستخدمون منسوبون إلى صفحات',
    },
    {
        label: 'المشاهدات',
        value: formatNumber(props.stats.views30d),
        sub: 'خلال آخر ٣٠ يومًا',
    },
    {
        label: 'الزوار الفريدون',
        value: formatNumber(props.stats.uniqueVisitors30d),
        sub: 'حسب عنوان IP، آخر ٣٠ يومًا',
    },
    {
        label: 'استخدام البوت',
        value: formatNumber(props.stats.botUses30d),
        sub: 'خلال آخر ٣٠ يومًا',
    },
    {
        label: 'الأمر الأكثر استخدامًا',
        value: props.stats.topCommand?.name ?? '—',
        sub: props.stats.topCommand ? `${formatNumber(props.stats.topCommand.uses)} مرة` : 'لا توجد بيانات بعد',
    },
]);

function toChartPoints(points: ChartPoint[] | undefined): LineChartPoint[] {
    return (points ?? []).map((point) => ({ label: formatShortDate(point.date), value: point.count }));
}

const confirmingCacheClear = ref(false);
const clearingCache = ref(false);

function clearCache(): void {
    clearingCache.value = true;

    router.post(
        '/manage/cache/clear',
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                confirmingCacheClear.value = false;
            },
            onFinish: () => {
                clearingCache.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="لوحة التحكم" />
    <PageHeader title="لوحة التحكم" description="نظرة عامة على الصفحات والزيارات وأوامر البوت">
        <template #actions>
            <Button variant="outline" @click="confirmingCacheClear = true">
                <Eraser />
                مسح الكاش
            </Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <div v-for="tile in tiles" :key="tile.label" class="rounded-lg border border-border bg-card p-4">
                <p class="text-sm text-muted-foreground">{{ tile.label }}</p>
                <p class="mt-1 truncate text-2xl font-bold tabular-nums" dir="ltr" :title="tile.value">{{ tile.value }}</p>
                <p class="mt-1 truncate text-xs text-muted-foreground">{{ tile.sub }}</p>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-lg border border-border bg-card p-4">
                <h2 class="mb-3 font-semibold">مشاهدات الصفحات خلال آخر ٣٠ يومًا</h2>
                <Deferred data="viewsChart">
                    <template #fallback>
                        <Skeleton class="h-48 w-full" />
                    </template>
                    <LineChart :points="toChartPoints(viewsChart)" color="var(--chart-2)" label="مشاهدات الصفحات خلال آخر ٣٠ يومًا" />
                </Deferred>
            </section>

            <section class="rounded-lg border border-border bg-card p-4">
                <h2 class="mb-3 font-semibold">استخدام أوامر البوت خلال آخر ٣٠ يومًا</h2>
                <Deferred data="commandsChart">
                    <template #fallback>
                        <Skeleton class="h-48 w-full" />
                    </template>
                    <LineChart :points="toChartPoints(commandsChart)" color="var(--chart-1)" label="استخدام أوامر البوت خلال آخر ٣٠ يومًا" />
                </Deferred>
            </section>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-lg border border-border bg-card p-4">
                <h2 class="mb-3 font-semibold">آخر الصفحات المحدثة</h2>
                <Deferred data="latestPages">
                    <template #fallback>
                        <div class="space-y-2">
                            <Skeleton v-for="i in 5" :key="i" class="h-8 w-full" />
                        </div>
                    </template>
                    <ul v-if="latestPages?.length" class="divide-y divide-border">
                        <li v-for="page in latestPages" :key="page.id" class="flex items-center justify-between gap-3 py-2">
                            <Link :href="`/manage/pages/${page.id}/edit`" class="min-w-0 truncate text-sm font-medium hover:underline">
                                {{ page.title }}
                            </Link>
                            <span v-if="page.updated_at" class="shrink-0 text-xs text-muted-foreground">
                                {{ formatRelativeTime(page.updated_at) }}
                            </span>
                        </li>
                    </ul>
                    <p v-else class="py-6 text-center text-sm text-muted-foreground">لا توجد صفحات بعد.</p>
                </Deferred>
            </section>

            <section class="rounded-lg border border-border bg-card p-4">
                <h2 class="mb-3 font-semibold">الصفحات الأكثر مشاهدة</h2>
                <Deferred data="mostViewed">
                    <template #fallback>
                        <div class="space-y-2">
                            <Skeleton v-for="i in 5" :key="i" class="h-8 w-full" />
                        </div>
                    </template>
                    <ul v-if="mostViewed?.length" class="divide-y divide-border">
                        <li v-for="page in mostViewed" :key="page.id" class="flex items-center justify-between gap-3 py-2">
                            <Link :href="`/manage/pages/${page.id}/edit`" class="min-w-0 truncate text-sm font-medium hover:underline">
                                {{ page.title }}
                            </Link>
                            <span class="shrink-0 text-xs text-muted-foreground tabular-nums" dir="ltr">{{ formatNumber(page.views) }}</span>
                        </li>
                    </ul>
                    <p v-else class="py-6 text-center text-sm text-muted-foreground">لا توجد مشاهدات مسجلة بعد.</p>
                </Deferred>
            </section>

            <section class="rounded-lg border border-border bg-card p-4">
                <h2 class="mb-3 font-semibold">الأوامر الأكثر استخدامًا</h2>
                <Deferred data="topCommands">
                    <template #fallback>
                        <div class="space-y-2">
                            <Skeleton v-for="i in 5" :key="i" class="h-8 w-full" />
                        </div>
                    </template>
                    <ul v-if="topCommands?.length" class="divide-y divide-border">
                        <li v-for="command in topCommands" :key="command.command" class="flex items-center justify-between gap-3 py-2">
                            <span class="min-w-0 truncate font-mono text-sm" dir="ltr">{{ command.command }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground tabular-nums" dir="ltr">{{ formatNumber(command.uses) }}</span>
                        </li>
                    </ul>
                    <p v-else class="py-6 text-center text-sm text-muted-foreground">لا توجد أوامر مسجلة بعد.</p>
                </Deferred>
            </section>
        </div>
    </div>

    <ConfirmDialog v-model:open="confirmingCacheClear" title="مسح الكاش" confirm-label="مسح" :processing="clearingCache" @confirm="clearCache">
        سيُمسح كاش الموقع ويُعاد بناؤه تلقائيًا عند الزيارات القادمة.
    </ConfirmDialog>
</template>
