<script setup lang="ts">
import EmptyState from '@/components/manage/EmptyState.vue';
import type { JoinRow } from '@/components/manage/invites/types';
import { sourceLabels } from '@/components/manage/invites/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatNumber, formatRelativeTime } from '@/lib/formatters';
import { ExternalLink, SearchX, UserCheck, Users } from 'lucide-vue-next';

defineProps<{
    joins: JoinRow[];
    searching?: boolean;
}>();

function initials(name: string): string {
    return name.trim().charAt(0) || '؟';
}
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-border bg-card" aria-labelledby="joins-heading">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-4 py-4 sm:px-5">
            <div>
                <h2 id="joins-heading" class="font-semibold">{{ searching ? 'الانضمامات المطابقة' : 'سجل الانضمام' }}</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ searching ? 'نتائج تطابق العضو أو المشرف أو الرابط أو المجموعة.' : 'أحدث ١٠٠ عملية انضمام ضمن التصفية الحالية.' }}
                </p>
            </div>
            <Badge v-if="joins.length" variant="secondary" dir="ltr" class="tabular-nums">{{ formatNumber(joins.length) }}</Badge>
        </div>

        <EmptyState
            v-if="!joins.length"
            :icon="searching ? SearchX : Users"
            :title="searching ? 'لا يوجد انضمام مطابق' : 'لا توجد انضمامات مسجّلة'"
            :description="
                searching
                    ? 'جرّب الاسم أو @المعرّف أو رقم تيليجرام أو رابط الدعوة، أو وسّع التصفية.'
                    : 'ستظهر عمليات الانضمام هنا عند وصول تحديثات تيليجرام.'
            "
            class="m-4"
        />

        <ul v-else class="divide-y divide-border">
            <li v-for="join in joins" :key="join.id" class="px-4 py-4 transition-colors hover:bg-muted/30 sm:px-5">
                <div class="flex items-start gap-3">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary"
                        aria-hidden="true"
                    >
                        {{ initials(join.joiner) }}
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <p class="font-medium">
                                <bdi>{{ join.joiner }}</bdi>
                            </p>
                            <span v-if="join.joiner_username" dir="ltr" class="text-xs text-muted-foreground">@{{ join.joiner_username }}</span>
                            <span dir="ltr" class="text-xs text-muted-foreground tabular-nums">{{ join.joiner_telegram_user_id }}</span>
                        </div>

                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                            <span v-if="join.inviter || join.inviter_telegram_user_id" class="inline-flex items-center gap-1">
                                <UserCheck class="size-3.5 text-emerald-600" />
                                دعاه <bdi class="font-medium text-foreground">{{ join.inviter ?? join.inviter_telegram_user_id }}</bdi>
                                <span v-if="join.inviter_username && join.inviter" dir="ltr">(@{{ join.inviter_username }})</span>
                            </span>
                            <span v-else>{{ sourceLabels[join.source] ?? join.source }} · بلا مشرف منسوب</span>
                            <span v-if="join.chat_title"
                                ><bdi>{{ join.chat_title }}</bdi></span
                            >
                            <span v-if="join.joined_at" :title="formatDateTime(join.joined_at)">{{ formatRelativeTime(join.joined_at) }}</span>
                        </div>

                        <div
                            v-if="join.link_name || join.invite_link"
                            class="mt-2 flex min-w-0 items-center gap-2 rounded-md bg-muted/60 px-2.5 py-1.5"
                        >
                            <span v-if="join.link_name" class="shrink-0 text-xs font-medium"
                                ><bdi>{{ join.link_name }}</bdi></span
                            >
                            <span
                                v-if="join.invite_link"
                                dir="ltr"
                                class="min-w-0 flex-1 truncate text-start font-mono text-xs text-muted-foreground"
                            >
                                {{ join.invite_link }}
                            </span>
                            <Button v-if="join.invite_link" as-child variant="ghost" size="icon-sm" class="-my-1 size-7">
                                <a :href="join.invite_link" target="_blank" rel="noreferrer" :aria-label="`فتح الرابط ${join.invite_link}`">
                                    <ExternalLink />
                                </a>
                            </Button>
                        </div>
                    </div>
                </div>
            </li>
        </ul>
    </section>
</template>
