<script setup lang="ts">
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, UsersRound } from 'lucide-vue-next';

defineOptions({ layout: ManageLayout });

/** One chat that has teams — rows appear when a group admin creates the first team from Telegram. */
interface ChatRow {
    chat_id: string;
    title: string | null;
    teams_count: number;
    members_count: number;
}

defineProps<{
    chats: ChatRow[];
}>();
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
        description="تُنشأ الفرق من داخل مجموعات تيليجرام بأمر «فريق جديد» من أحد مشرفي المجموعة، وستظهر هنا فور إنشائها."
    />

    <ul v-else class="overflow-hidden rounded-lg border border-border">
        <li v-for="chat in chats" :key="chat.chat_id" class="border-b border-border last:border-b-0">
            <Link :href="`/manage/telegram-teams/${chat.chat_id}`" class="flex items-center gap-3 p-3 transition-colors hover:bg-muted/50">
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-medium">{{ chat.title ?? 'مجموعة بدون اسم' }}</span>
                        <Badge variant="secondary">{{ chat.teams_count }} فريق</Badge>
                        <Badge variant="outline">{{ chat.members_count }} عضوية</Badge>
                    </div>
                    <div class="text-xs text-muted-foreground">
                        <span dir="ltr" class="tabular-nums">{{ chat.chat_id }}</span>
                    </div>
                </div>
                <ChevronLeft class="size-4 text-muted-foreground" />
            </Link>
        </li>
    </ul>
</template>
