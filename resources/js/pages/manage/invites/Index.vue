<script setup lang="ts">
import InviteJoinsList from '@/components/manage/invites/InviteJoinsList.vue';
import InviteLinksList from '@/components/manage/invites/InviteLinksList.vue';
import InviteRequestsList from '@/components/manage/invites/InviteRequestsList.vue';
import InvitesOverview from '@/components/manage/invites/InvitesOverview.vue';
import type { InviteFilters, InviteLinkRow, InviteStats, InviteView, JoinRow, LeaderboardRow, RequestRow } from '@/components/manage/invites/types';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Deferred, Head, router } from '@inertiajs/vue3';
import { ChartNoAxesColumn, FilterX, History, Link2, Search, Users, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    filters: InviteFilters;
    chats: { chat_id: string; title: string | null }[];
    stats: InviteStats;
    leaderboard: LeaderboardRow[];
    recentJoins?: JoinRow[];
    inviteLinks?: InviteLinkRow[];
    preTrackingRequests?: RequestRow[];
}>();

/** Reka UI selects reserve the empty string, so "all" is the explicit sentinel. */
const ALL = 'all';

const period = ref(props.filters.period);
const chat = ref(props.filters.chat ?? ALL);
const search = ref(props.filters.q);
const view = ref<InviteView>(props.filters.view);
const linkStatus = ref(props.filters.link_status);
const joinSource = ref(props.filters.join_source);
const loading = ref(false);

const periodOptions = [
    { value: '24h', label: '٢٤ ساعة' },
    { value: '7d', label: '٧ أيام' },
    { value: '30d', label: '٣٠ يومًا' },
    { value: 'all', label: 'كل المدة' },
];

const viewOptions: { value: InviteView; label: string; description: string; icon: typeof ChartNoAxesColumn }[] = [
    { value: 'overview', label: 'نظرة عامة', description: 'الأداء والمشرفون', icon: ChartNoAxesColumn },
    { value: 'joins', label: 'الانضمامات', description: 'من انضم ومن دعاه', icon: Users },
    { value: 'links', label: 'الروابط', description: 'فحص الرابط وحالته', icon: Link2 },
    { value: 'requests', label: 'سجل الطلبات', description: 'استخدام أمر «رابط»', icon: History },
];

const isSearching = computed(() => props.filters.q !== '');
const hasFilters = computed(() => period.value !== 'all' || chat.value !== ALL || linkStatus.value !== 'all' || joinSource.value !== 'all');

function query(): Record<string, string> {
    const values: Record<string, string> = {};
    const trimmedSearch = search.value.trim();

    if (view.value !== 'overview') values.view = view.value;
    if (period.value !== 'all') values.period = period.value;
    if (chat.value !== ALL) values.chat = chat.value;
    if (trimmedSearch) values.q = trimmedSearch;
    if (linkStatus.value !== 'all') values.link_status = linkStatus.value;
    if (joinSource.value !== 'all') values.join_source = joinSource.value;

    return values;
}

function reload(): void {
    loading.value = true;
    router.get('/manage/invites', query(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            loading.value = false;
        },
    });
}

function setView(nextView: InviteView): void {
    if (search.value.trim()) {
        suppressNextSearchWatch = true;
        search.value = '';
    }

    view.value = nextView;

    if (!search.value.trim()) {
        if (nextView !== 'links') linkStatus.value = 'all';
        if (nextView !== 'joins') joinSource.value = 'all';
    }

    reload();
}

function setPeriod(nextPeriod: string): void {
    period.value = nextPeriod;
    reload();
}

function setChat(value: unknown): void {
    chat.value = String(value);
    reload();
}

function setLinkStatus(value: unknown): void {
    linkStatus.value = String(value);
    reload();
}

function setJoinSource(value: unknown): void {
    joinSource.value = String(value);
    reload();
}

function resetFilters(): void {
    period.value = 'all';
    chat.value = ALL;
    linkStatus.value = 'all';
    joinSource.value = 'all';
    reload();
}

function clearSearch(): void {
    search.value = '';
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;
let suppressNextSearchWatch = false;

watch(search, (value) => {
    clearTimeout(searchTimer);

    if (suppressNextSearchWatch) {
        suppressNextSearchWatch = false;
        return;
    }

    if (value.trim() === props.filters.q) return;

    searchTimer = setTimeout(reload, 400);
});

onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<template>
    <Head title="الدعوات" />
    <PageHeader title="الدعوات" description="اعرف من دعا من، وافحص أي رابط، وتابع أثر المشرفين دون خلط بين الطلبات والانضمامات." />

    <div class="space-y-5">
        <section
            class="rounded-xl border border-border bg-gradient-to-b from-primary/[0.06] to-card p-4 sm:p-5"
            aria-labelledby="invite-search-heading"
        >
            <div class="max-w-3xl">
                <h2 id="invite-search-heading" class="font-semibold">استعلام سريع</h2>
                <p class="mt-1 text-sm text-muted-foreground">الصق رابطًا أو عدة روابط معًا، أو ابحث باسم عضو أو مشرف أو @المعرّف أو رقم تيليجرام.</p>
            </div>

            <div class="relative mt-4 max-w-3xl">
                <Search class="pointer-events-none absolute start-3 top-1/2 size-5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="الصق رابطًا أو رابطين، أو اكتب @username أو رقم تيليجرام"
                    class="h-12 bg-background ps-11 pe-11 text-base shadow-sm"
                    aria-label="البحث في الروابط والانضمامات"
                    autocomplete="off"
                />
                <Button
                    v-if="search"
                    variant="ghost"
                    size="icon-sm"
                    class="absolute end-1.5 top-1/2 -translate-y-1/2"
                    aria-label="مسح البحث"
                    @click="clearSearch"
                >
                    <X />
                </Button>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <span>يمكنك البحث بـ:</span>
                <span class="rounded-full border border-border bg-background px-2.5 py-1"> رابط t.me </span>
                <span class="rounded-full border border-border bg-background px-2.5 py-1">عدة روابط دفعة واحدة</span>
                <span class="rounded-full border border-border bg-background px-2.5 py-1"> @المعرّف </span>
                <span class="rounded-full border border-border bg-background px-2.5 py-1">اسم العضو أو المشرف</span>
                <span class="rounded-full border border-border bg-background px-2.5 py-1">رقم تيليجرام</span>
            </div>
        </section>

        <nav class="grid grid-cols-2 gap-2 rounded-xl border border-border bg-muted/40 p-1.5 lg:grid-cols-4" aria-label="أقسام الدعوات">
            <button
                v-for="option in viewOptions"
                :key="option.value"
                type="button"
                class="flex min-w-0 items-center gap-2 rounded-lg px-3 py-2.5 text-start transition-colors"
                :class="
                    view === option.value
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:bg-background/70 hover:text-foreground'
                "
                :aria-current="view === option.value ? 'page' : undefined"
                @click="setView(option.value)"
            >
                <component :is="option.icon" class="size-4 shrink-0" aria-hidden="true" />
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium">{{ option.label }}</span>
                    <span class="hidden truncate text-xs text-muted-foreground sm:block">{{ option.description }}</span>
                </span>
            </button>
        </nav>

        <section class="rounded-xl border border-border bg-card p-3 sm:p-4" aria-labelledby="filters-heading">
            <div class="flex flex-wrap items-end gap-3">
                <fieldset v-if="view !== 'requests' || isSearching" class="min-w-0">
                    <legend id="filters-heading" class="mb-1.5 text-xs font-medium text-muted-foreground">الفترة الزمنية</legend>
                    <div class="flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                        <button
                            v-for="option in periodOptions"
                            :key="option.value"
                            type="button"
                            class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                            :class="
                                period === option.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'
                            "
                            :aria-pressed="period === option.value"
                            @click="setPeriod(option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </fieldset>

                <div v-if="chats.length > 1" class="space-y-1.5">
                    <label class="block text-xs font-medium text-muted-foreground">المجموعة</label>
                    <Select :model-value="chat" @update:model-value="setChat">
                        <SelectTrigger class="w-56" aria-label="تصفية حسب المجموعة">
                            <SelectValue placeholder="كل المجموعات" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">كل المجموعات</SelectItem>
                            <SelectItem v-for="option in chats" :key="option.chat_id" :value="option.chat_id">
                                {{ option.title ?? option.chat_id }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="view === 'links' || isSearching" class="space-y-1.5">
                    <label class="block text-xs font-medium text-muted-foreground">حالة الرابط</label>
                    <Select :model-value="linkStatus" @update:model-value="setLinkStatus">
                        <SelectTrigger class="w-40" aria-label="تصفية حسب حالة الرابط"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">كل الحالات</SelectItem>
                            <SelectItem value="available">متاح</SelectItem>
                            <SelectItem value="used">مُستخدم</SelectItem>
                            <SelectItem value="expired">منتهي</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="view === 'joins' || isSearching" class="space-y-1.5">
                    <label class="block text-xs font-medium text-muted-foreground">طريقة الانضمام</label>
                    <Select :model-value="joinSource" @update:model-value="setJoinSource">
                        <SelectTrigger class="w-48" aria-label="تصفية حسب طريقة الانضمام"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">كل الطرق</SelectItem>
                            <SelectItem value="invite_link">عبر رابط دعوة</SelectItem>
                            <SelectItem value="added_by_admin">أضافه مشرف</SelectItem>
                            <SelectItem value="self">انضم مباشرة</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <Button v-if="hasFilters" variant="ghost" size="sm" class="text-muted-foreground" @click="resetFilters">
                    <FilterX />
                    إعادة ضبط التصفية
                </Button>

                <span v-if="loading" class="self-center text-xs text-muted-foreground" role="status">جارٍ تحديث النتائج…</span>
            </div>

            <p v-if="view === 'requests'" class="mt-2 text-xs text-muted-foreground">هذا القسم تاريخي؛ فلتر المجموعة فقط هو الذي ينطبق عليه.</p>
            <p v-else class="mt-2 text-xs text-muted-foreground">
                الفترة تنطبق على تاريخ الانضمام في السجل، وعلى تاريخ إنشاء الرابط في دليل الروابط.
            </p>
        </section>

        <div v-if="isSearching" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold">
                        نتائج البحث عن «<bdi>{{ filters.q }}</bdi
                        >»
                    </h2>
                    <p class="text-sm text-muted-foreground">نعرض الروابط والانضمامات المطابقة معًا كي لا تضطر إلى اختيار نوع البحث مسبقًا.</p>
                </div>
                <Button variant="outline" size="sm" @click="clearSearch"><X /> مسح البحث</Button>
            </div>

            <Deferred data="inviteLinks">
                <template #fallback><Skeleton class="h-48 w-full rounded-xl" /></template>
                <InviteLinksList :links="inviteLinks ?? []" searching />
            </Deferred>

            <Deferred data="recentJoins">
                <template #fallback><Skeleton class="h-48 w-full rounded-xl" /></template>
                <InviteJoinsList :joins="recentJoins ?? []" searching />
            </Deferred>
        </div>

        <InvitesOverview v-else-if="view === 'overview'" :stats="stats" :leaderboard="leaderboard" />

        <Deferred v-else-if="view === 'joins'" data="recentJoins">
            <template #fallback><Skeleton class="h-72 w-full rounded-xl" /></template>
            <InviteJoinsList :joins="recentJoins ?? []" />
        </Deferred>

        <Deferred v-else-if="view === 'links'" data="inviteLinks">
            <template #fallback><Skeleton class="h-72 w-full rounded-xl" /></template>
            <InviteLinksList :links="inviteLinks ?? []" />
        </Deferred>

        <Deferred v-else data="preTrackingRequests">
            <template #fallback><Skeleton class="h-72 w-full rounded-xl" /></template>
            <InviteRequestsList :requests="preTrackingRequests ?? []" />
        </Deferred>
    </div>
</template>
