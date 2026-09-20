<script setup lang="ts">
import EmptyState from '@/components/manage/EmptyState.vue';
import type { RequestRow } from '@/components/manage/invites/types';
import { displayTelegramIdentity } from '@/components/manage/invites/types';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { formatDateTime, formatNumber, formatRelativeTime } from '@/lib/formatters';
import { Archive, Info, SquareTerminal } from 'lucide-vue-next';

defineProps<{
    requests: RequestRow[];
}>();
</script>

<template>
    <div class="space-y-4">
        <Alert>
            <Info />
            <AlertTitle>سجل تاريخي، وليس مقياس انضمام</AlertTitle>
            <AlertDescription>
                هذه الأرقام تحصي استخدام أمر «رابط» منذ إطلاقه، وتشمل المحاولات المرفوضة. لذلك لا تتأثر بفلتر الفترة ولا تعني أن شخصًا انضم.
            </AlertDescription>
        </Alert>

        <section class="overflow-hidden rounded-xl border border-border bg-card" aria-labelledby="requests-heading">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-4 py-4 sm:px-5">
                <div>
                    <h2 id="requests-heading" class="font-semibold">طلبات الأمر عبر كل التاريخ</h2>
                    <p class="mt-1 text-sm text-muted-foreground">مرجع منفصل لفهم النشاط الذي سبق نظام تتبّع الروابط والانضمامات.</p>
                </div>
                <Badge v-if="requests.length" variant="secondary" dir="ltr" class="tabular-nums">{{ formatNumber(requests.length) }}</Badge>
            </div>

            <EmptyState
                v-if="!requests.length"
                :icon="Archive"
                title="لا توجد طلبات مسجّلة"
                description="لم يرصد عدّاد الأوامر استخدامًا لأمر «رابط» في المجموعة المحددة."
                class="m-4"
            />

            <ul v-else class="divide-y divide-border">
                <li v-for="row in requests" :key="row.telegram_user_id" class="flex items-center gap-3 px-4 py-3 sm:px-5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                        <SquareTerminal class="size-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">
                            <bdi>{{ displayTelegramIdentity(row) }}</bdi>
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            <span dir="ltr" class="tabular-nums">{{ row.telegram_user_id }}</span>
                            <template v-if="row.before_tracking">
                                · نحو <span dir="ltr" class="tabular-nums">{{ formatNumber(row.before_tracking) }}</span> طلب قبل بدء التتبّع
                            </template>
                            <template v-if="row.last_used_at">
                                · آخر طلب <span :title="formatDateTime(row.last_used_at)">{{ formatRelativeTime(row.last_used_at) }}</span>
                            </template>
                        </p>
                    </div>
                    <div class="text-end">
                        <p class="text-xl font-bold tabular-nums" dir="ltr">{{ formatNumber(row.requests) }}</p>
                        <p class="text-xs text-muted-foreground">طلب</p>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
