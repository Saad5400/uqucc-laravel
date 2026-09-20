<script setup lang="ts">
import EmptyState from '@/components/manage/EmptyState.vue';
import type { InviteStats, LeaderboardRow } from '@/components/manage/invites/types';
import { displayTelegramIdentity } from '@/components/manage/invites/types';
import { formatDateTime, formatNumber, formatRelativeTime } from '@/lib/formatters';
import { Link2, MousePointerClick, UserCheck, UserPlus, Users } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    stats: InviteStats;
    leaderboard: LeaderboardRow[];
}>();

const tiles = computed(() => [
    {
        label: 'الانضمامات',
        value: formatNumber(props.stats.joins),
        detail:
            props.stats.attributionRate === null
                ? 'لا توجد انضمامات في هذه الفترة'
                : `${formatNumber(props.stats.attributedJoins)} منها منسوبة لمشرف (${formatNumber(props.stats.attributionRate)}%)`,
        icon: Users,
    },
    {
        label: 'روابط الدعوة',
        value: formatNumber(props.stats.links),
        detail: `${formatNumber(props.stats.usedLinks)} مستخدم · ${formatNumber(props.stats.unusedLinks)} غير مستخدم`,
        icon: Link2,
    },
    {
        label: 'نسبة استخدام الروابط',
        value: props.stats.usageRate === null ? '—' : `${formatNumber(props.stats.usageRate)}%`,
        detail: 'نسبة الروابط التي نتج عنها انضمام واحد على الأقل',
        icon: MousePointerClick,
    },
    {
        label: 'المشرفون المؤثرون',
        value: formatNumber(props.stats.inviters),
        detail: 'مشرفون نُسب إليهم انضمام واحد على الأقل',
        icon: UserCheck,
    },
]);

const maxJoins = computed(() => Math.max(1, ...props.leaderboard.map((row) => row.joins)));
</script>

<template>
    <div class="space-y-5">
        <section aria-labelledby="summary-heading">
            <div class="mb-3">
                <h2 id="summary-heading" class="font-semibold">ملخص الأداء</h2>
                <p class="text-sm text-muted-foreground">كل الأرقام أدناه تتبع الفترة والمجموعة المحددتين أعلاه.</p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article v-for="tile in tiles" :key="tile.label" class="rounded-xl border border-border bg-card p-4 shadow-xs">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">{{ tile.label }}</p>
                            <p class="mt-2 text-3xl font-bold tabular-nums" dir="ltr">{{ tile.value }}</p>
                        </div>
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <component :is="tile.icon" class="size-4" aria-hidden="true" />
                        </span>
                    </div>
                    <p class="mt-3 text-xs leading-5 text-muted-foreground">{{ tile.detail }}</p>
                </article>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-border bg-card" aria-labelledby="leaderboard-heading">
            <div class="border-b border-border px-4 py-4 sm:px-5">
                <h2 id="leaderboard-heading" class="font-semibold">أثر المشرفين</h2>
                <p class="mt-1 text-sm text-muted-foreground">ترتيب واضح حسب الانضمامات الفعلية، لا حسب عدد الروابط المطلوبة.</p>
            </div>

            <EmptyState
                v-if="!leaderboard.length"
                :icon="UserPlus"
                title="لا توجد بيانات في هذه الفترة"
                description="غيّر الفترة أو المجموعة، أو راجع سجل الروابط للتأكد من بدء التتبّع."
                class="m-4"
            />

            <ol v-else class="divide-y divide-border">
                <li
                    v-for="(row, index) in leaderboard"
                    :key="row.telegram_user_id"
                    class="grid grid-cols-[2.25rem_1fr_auto] items-center gap-3 px-4 py-3 sm:px-5"
                >
                    <span
                        class="flex size-8 items-center justify-center rounded-full text-sm font-semibold tabular-nums"
                        :class="index < 3 ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'"
                        dir="ltr"
                    >
                        {{ index + 1 }}
                    </span>

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            <p class="truncate font-medium">
                                <bdi>{{ displayTelegramIdentity(row) }}</bdi>
                            </p>
                            <span v-if="row.username && row.name" class="text-xs text-muted-foreground" dir="ltr">@{{ row.username }}</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                            <div class="h-full rounded-full bg-primary" :style="{ width: `${Math.max(2, (row.joins / maxJoins) * 100)}%` }" />
                        </div>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            <span dir="ltr" class="tabular-nums">{{ formatNumber(row.links) }}</span> رابط
                            <template v-if="row.last_join_at">
                                · آخر انضمام
                                <span :title="formatDateTime(row.last_join_at)">{{ formatRelativeTime(row.last_join_at) }}</span>
                            </template>
                        </p>
                    </div>

                    <div class="text-end">
                        <p class="text-xl font-bold tabular-nums" dir="ltr">{{ formatNumber(row.joins) }}</p>
                        <p class="text-xs text-muted-foreground">انضمام</p>
                    </div>
                </li>
            </ol>
        </section>
    </div>
</template>
