<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { formatDateTime, formatRelativeTime } from '@/lib/formatters';
import { UserMinus } from 'lucide-vue-next';
import { computed } from 'vue';
import type { TeamMember } from './types';

const props = defineProps<{
    member: TeamMember;
    teamName: string;
}>();

defineEmits<{
    remove: [];
}>();

/**
 * The exact consent time and the approving admin's raw Telegram id are rarely
 * needed, so they live in the row's tooltip instead of the visible line.
 */
const detailsTitle = computed(() => {
    const parts = [`أضافه ${props.member.added_by_telegram_id}`];

    if (props.member.consented_at) {
        parts.unshift(`وافق ${formatDateTime(props.member.consented_at)}`);
    }

    return parts.join(' — ');
});
</script>

<template>
    <li class="flex items-center gap-2 px-3 py-2">
        <div class="min-w-0 flex-1 space-y-0.5">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-sm">
                <bdi class="truncate font-medium">{{ member.name }}</bdi>
                <bdi v-if="member.username" dir="ltr" class="truncate text-xs text-muted-foreground">@{{ member.username }}</bdi>
            </div>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground" :title="detailsTitle">
                <bdi dir="ltr" class="tabular-nums">{{ member.telegram_user_id }}</bdi>
                <template v-if="member.consented_at">
                    <span aria-hidden="true" class="text-muted-foreground/50">·</span>
                    <span>وافق {{ formatRelativeTime(member.consented_at) }}</span>
                </template>
            </div>
        </div>

        <Button
            variant="ghost"
            size="icon-sm"
            class="shrink-0 text-destructive-foreground"
            :aria-label="`إزالة ${member.name} من ${teamName}`"
            title="إزالة من الفريق"
            @click="$emit('remove')"
        >
            <UserMinus />
        </Button>
    </li>
</template>
