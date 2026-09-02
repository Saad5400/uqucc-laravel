<script setup lang="ts">
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { formatDateTime, formatNumber, formatRelativeTime } from '@/lib/formatters';
import { Deferred, Head, router } from '@inertiajs/vue3';
import { UserPlus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

defineOptions({ layout: ManageLayout });

interface LeaderboardRow {
    telegram_user_id: string;
    username: string | null;
    name: string | null;
    joins: number;
    links: number;
    last_join_at: string | null;
}

interface JoinRow {
    id: number;
    joiner: string;
    joiner_username: string | null;
    joiner_telegram_user_id: string;
    inviter: string | null;
    inviter_username: string | null;
    inviter_telegram_user_id: string | null;
    chat_title: string | null;
    source: string;
    joined_at: string | null;
}

interface RequestRow {
    telegram_user_id: string;
    username: string | null;
    name: string | null;
    requests: number;
    before_tracking: number;
    last_used_at: string | null;
}

const props = defineProps<{
    filters: { period: string; chat: string | null; q: string };
    chats: { chat_id: string; title: string | null }[];
    stats: {
        joins: number;
        attributedJoins: number;
        links: number;
        unusedLinks: number;
        inviters: number;
        conversion: number | null;
    };
    leaderboard: LeaderboardRow[];
    recentJoins?: JoinRow[];
    preTrackingRequests?: RequestRow[];
}>();

/** Sentinel for "every group" — reka-ui selects reserve the empty string. */
const ALL = 'all';

const period = ref(props.filters.period);
const chat = ref(props.filters.chat ?? ALL);
const search = ref(props.filters.q);

const periodLabels: Record<string, string> = {
    '24h': 'آخر ٢٤ ساعة',
    '7d': 'آخر ٧ أيام',
    '30d': 'آخر ٣٠ يومًا',
    all: 'منذ البداية',
};

const sourceLabels: Record<string, string> = {
    invite_link: 'رابط دعوة',
    added_by_admin: 'أضافه مشرف',
    self: 'انضم مباشرة',
};

function reload(): void {
    router.get(
        '/manage/invites',
        {
            period: period.value,
            ...(chat.value === ALL ? {} : { chat: chat.value }),
            ...(search.value.trim() === '' ? {} : { q: search.value.trim() }),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch([period, chat], reload);

/** Typing a member's name should not fire a request per keystroke. */
let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(reload, 300);
});

const isSearching = computed(() => props.filters.q.trim() !== '');

const tiles = computed(() => [
    {
        label: 'الأعضاء الذين انضموا',
        value: formatNumber(props.stats.joins),
        sub: `منهم ${formatNumber(props.stats.attributedJoins)} منسوبون لمشرف`,
    },
    {
        label: 'المشرفون الداعون',
        value: formatNumber(props.stats.inviters),
        sub: 'من انضم أحدٌ عبر روابطهم',
    },
    {
        label: 'الروابط المُنشأة',
        value: formatNumber(props.stats.links),
        sub: `${formatNumber(props.stats.unusedLinks)} لم يُستخدم بعد`,
    },
    {
        label: 'نسبة الاستخدام',
        value: props.stats.conversion === null ? '—' : `${props.stats.conversion}%`,
        sub: 'من الروابط المُنشأة تحوّلت إلى انضمام',
    },
]);

/** Rank badges for the podium — the leaderboard is the point of this page. */
const medals = ['🥇', '🥈', '🥉'];

function displayName(row: { name: string | null; username: string | null; telegram_user_id: string }): string {
    return row.name || (row.username ? `@${row.username}` : row.telegram_user_id);
}

const maxJoins = computed(() => Math.max(1, ...props.leaderboard.map((row) => row.joins)));
</script>

<template>
    <Head title="روابط الدعوة" />
    <PageHeader title="روابط الدعوة" description="من طلب روابط الدعوة عبر أمر «رابط» في تيليجرام، ومن انضم فعليًا عبر كل رابط" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-center gap-2">
            <Input
                v-model="search"
                type="search"
                placeholder="ابحث باسم العضو أو معرّفه لمعرفة من دعاه…"
                class="w-full sm:max-w-sm"
                aria-label="البحث عن عضو أو مشرف"
            />

            <Select v-model="period">
                <SelectTrigger class="w-40" aria-label="الفترة الزمنية">
                    <SelectValue placeholder="الفترة" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="(label, value) in periodLabels" :key="value" :value="value">{{ label }}</SelectItem>
                </SelectContent>
            </Select>

            <Select v-if="chats.length > 1" v-model="chat">
                <SelectTrigger class="w-56" aria-label="المجموعة">
                    <SelectValue placeholder="المجموعة" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">كل المجموعات</SelectItem>
                    <SelectItem v-for="option in chats" :key="option.chat_id" :value="option.chat_id">
                        {{ option.title ?? option.chat_id }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div v-for="tile in tiles" :key="tile.label" class="rounded-lg border border-border bg-card p-4">
                <p class="text-sm text-muted-foreground">{{ tile.label }}</p>
                <p class="mt-1 truncate text-2xl font-bold tabular-nums" dir="ltr" :title="tile.value">{{ tile.value }}</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ tile.sub }}</p>
            </div>
        </div>

        <section class="rounded-lg border border-border bg-card p-4">
            <h2 class="mb-3 font-semibold">ترتيب المشرفين حسب عدد المنضمين</h2>

            <EmptyState
                v-if="!leaderboard.length"
                :icon="UserPlus"
                title="لا توجد انضمامات مسجّلة بعد"
                description="يبدأ التسجيل من لحظة تفعيل التتبّع: كل رابط يُنشئه أمر «رابط» يُحفظ باسم صاحبه، ويُنسب إليه كل من ينضم عبره."
            />

            <ul v-else class="divide-y divide-border">
                <li v-for="(row, index) in leaderboard" :key="row.telegram_user_id" class="flex items-center gap-3 py-3">
                    <span class="w-8 shrink-0 text-center text-lg tabular-nums" dir="ltr">
                        {{ medals[index] ?? index + 1 }}
                    </span>

                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="font-medium">{{ displayName(row) }}</span>
                            <span v-if="row.username && row.name" dir="ltr" class="text-xs text-muted-foreground">@{{ row.username }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                            <div class="h-full rounded-full bg-primary" :style="{ width: `${(row.joins / maxJoins) * 100}%` }" />
                        </div>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                            <span
                                ><span dir="ltr" class="tabular-nums">{{ formatNumber(row.links) }}</span> رابط</span
                            >
                            <span v-if="row.last_join_at" :title="formatDateTime(row.last_join_at)">
                                آخر انضمام {{ formatRelativeTime(row.last_join_at) }}
                            </span>
                        </div>
                    </div>

                    <div class="shrink-0 text-end">
                        <p class="text-xl font-bold tabular-nums" dir="ltr">{{ formatNumber(row.joins) }}</p>
                        <p class="text-xs text-muted-foreground">عضو</p>
                    </div>
                </li>
            </ul>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-lg border border-border bg-card p-4">
                <h2 class="mb-1 font-semibold">{{ isSearching ? 'نتائج البحث' : 'آخر الانضمامات' }}</h2>
                <p class="mb-3 text-xs text-muted-foreground">
                    {{
                        isSearching
                            ? `كل من يطابق «${filters.q}» — عضوًا كان أو مشرفًا دعا غيره، حتى ١٠٠ نتيجة.`
                            : 'أحدث ١٠٠ انضمام، بغضّ النظر عن الفترة المختارة.'
                    }}
                </p>

                <Deferred data="recentJoins">
                    <template #fallback>
                        <div class="space-y-2">
                            <Skeleton v-for="i in 6" :key="i" class="h-10 w-full" />
                        </div>
                    </template>

                    <ul v-if="recentJoins?.length" class="divide-y divide-border">
                        <li v-for="join in recentJoins" :key="join.id" class="flex items-center justify-between gap-3 py-2">
                            <div class="min-w-0 space-y-0.5">
                                <p class="truncate text-sm font-medium">
                                    {{ join.joiner }}
                                    <span v-if="join.joiner_username" dir="ltr" class="text-xs font-normal text-muted-foreground">
                                        @{{ join.joiner_username }}
                                    </span>
                                </p>
                                <p class="truncate text-xs text-muted-foreground">
                                    <span v-if="join.inviter || join.inviter_telegram_user_id">
                                        دعاه {{ join.inviter ?? join.inviter_telegram_user_id }}
                                        <span v-if="join.inviter_username && join.inviter" dir="ltr">(@{{ join.inviter_username }})</span>
                                    </span>
                                    <span v-else>{{ sourceLabels[join.source] ?? join.source }} — بلا مشرف منسوب</span>
                                </p>
                                <p v-if="isSearching" dir="ltr" class="truncate text-xs text-muted-foreground tabular-nums">
                                    {{ join.joiner_telegram_user_id }}
                                </p>
                            </div>
                            <span v-if="join.joined_at" class="shrink-0 text-xs text-muted-foreground" :title="formatDateTime(join.joined_at)">
                                {{ formatRelativeTime(join.joined_at) }}
                            </span>
                        </li>
                    </ul>
                    <p v-else class="py-6 text-center text-sm text-muted-foreground">
                        {{ isSearching ? 'لا أحد يطابق هذا البحث بين الانضمامات المسجّلة.' : 'لم يُسجَّل أي انضمام بعد.' }}
                    </p>
                </Deferred>
            </section>

            <section class="rounded-lg border border-border bg-card p-4">
                <h2 class="mb-1 font-semibold">طلبات الرابط (كل التاريخ)</h2>
                <p class="mb-3 text-xs text-muted-foreground">
                    عدّاد استخدام أمر «رابط» منذ إطلاقه — يقيس الطلبات لا الانضمامات، ويشمل المحاولات المرفوضة لعدم الصلاحية. وهو كل ما يتوفّر عمّا
                    قبل بدء تتبّع الانضمامات.
                </p>

                <Deferred data="preTrackingRequests">
                    <template #fallback>
                        <div class="space-y-2">
                            <Skeleton v-for="i in 6" :key="i" class="h-10 w-full" />
                        </div>
                    </template>

                    <ul v-if="preTrackingRequests?.length" class="divide-y divide-border">
                        <li v-for="row in preTrackingRequests" :key="row.telegram_user_id" class="flex items-center justify-between gap-3 py-2">
                            <div class="min-w-0 space-y-0.5">
                                <p class="truncate text-sm font-medium">{{ displayName(row) }}</p>
                                <p class="text-xs text-muted-foreground">
                                    <span v-if="row.before_tracking">
                                        منها <span dir="ltr" class="tabular-nums">{{ formatNumber(row.before_tracking) }}</span> قبل بدء التتبّع
                                    </span>
                                    <span v-if="row.before_tracking && row.last_used_at"> · </span>
                                    <span v-if="row.last_used_at" :title="formatDateTime(row.last_used_at)">
                                        آخر طلب {{ formatRelativeTime(row.last_used_at) }}
                                    </span>
                                </p>
                            </div>
                            <Badge variant="secondary" class="shrink-0 tabular-nums" dir="ltr">{{ formatNumber(row.requests) }}</Badge>
                        </li>
                    </ul>
                    <p v-else class="py-6 text-center text-sm text-muted-foreground">لم يُستخدم الأمر بعد.</p>
                </Deferred>
            </section>
        </div>
    </div>
</template>
