<script setup lang="ts">
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import { arabicMemberships, arabicTeams } from '@/lib/arabic';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, UsersRound } from 'lucide-vue-next';
import { computed } from 'vue';

defineOptions({ layout: ManageLayout });

/** One chat that has teams — rows appear when a group admin creates the first team from Telegram. */
interface ChatRow {
    chat_id: string;
    title: string | null;
    teams_count: number;
    members_count: number;
}

const props = defineProps<{
    chats: ChatRow[];
}>();

const totals = computed(() => ({
    teams: props.chats.reduce((total, chat) => total + chat.teams_count, 0),
    members: props.chats.reduce((total, chat) => total + chat.members_count, 0),
}));
</script>

<template>
    <Head title="فرق التليجرام" />
    <PageHeader
        title="فرق التليجرام"
        description="فرق المجموعات التي ينشئها مشرفو المجموعات من داخل تيليجرام — التصنيفات والفرق تُدار من هنا، والانضمام يبقى بموافقة العضو داخل تيليجرام"
    />

    <EmptyState
        v-if="!chats.length"
        :icon="UsersRound"
        title="لا توجد فرق بعد"
        description="تُنشأ الفرق من داخل مجموعات تيليجرام برسالة «فريق جديد اسم الفريق» من أحد مشرفي المجموعة، وستظهر هنا فور إنشائها."
    />

    <div v-else class="space-y-4">
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-border bg-card p-3">
                <dt class="text-xs text-muted-foreground">المجموعات</dt>
                <dd class="text-xl font-semibold tabular-nums">{{ chats.length }}</dd>
            </div>
            <div class="rounded-lg border border-border bg-card p-3">
                <dt class="text-xs text-muted-foreground">الفرق</dt>
                <dd class="text-xl font-semibold tabular-nums">{{ totals.teams }}</dd>
            </div>
            <div class="col-span-2 rounded-lg border border-border bg-card p-3 sm:col-span-1">
                <dt class="text-xs text-muted-foreground">العضويات</dt>
                <dd class="text-xl font-semibold tabular-nums">{{ totals.members }}</dd>
            </div>
        </dl>

        <ul class="overflow-hidden rounded-lg border border-border">
            <li v-for="chat in chats" :key="chat.chat_id" class="border-b border-border last:border-b-0">
                <Link
                    :href="`/manage/telegram-teams/${chat.chat_id}`"
                    class="flex items-center gap-3 p-3 transition-colors hover:bg-muted/50"
                    :aria-label="`فرق ${chat.title ?? 'مجموعة بدون اسم'}`"
                >
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                        <UsersRound class="size-4" />
                    </span>

                    <div class="min-w-0 flex-1 space-y-1">
                        <bdi class="block truncate font-medium">{{ chat.title ?? 'مجموعة بدون اسم' }}</bdi>
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                            <span class="tabular-nums">{{ arabicTeams(chat.teams_count) }}</span>
                            <span aria-hidden="true" class="text-muted-foreground/50">·</span>
                            <span class="tabular-nums">{{ arabicMemberships(chat.members_count) }}</span>
                            <span aria-hidden="true" class="text-muted-foreground/50">·</span>
                            <bdi dir="ltr" class="tabular-nums">{{ chat.chat_id }}</bdi>
                        </div>
                    </div>

                    <ChevronLeft class="size-4 shrink-0 text-muted-foreground" />
                </Link>
            </li>
        </ul>
    </div>
</template>
