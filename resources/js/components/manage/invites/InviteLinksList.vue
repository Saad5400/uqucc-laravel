<script setup lang="ts">
import EmptyState from '@/components/manage/EmptyState.vue';
import type { InviteLinkRow } from '@/components/manage/invites/types';
import { linkStatusLabels } from '@/components/manage/invites/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatNumber, formatRelativeTime } from '@/lib/formatters';
import { Check, Copy, ExternalLink, Link2, SearchX } from 'lucide-vue-next';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

defineProps<{
    links: InviteLinkRow[];
    searching?: boolean;
}>();

const copiedId = ref<number | null>(null);

async function copyLink(link: InviteLinkRow): Promise<void> {
    try {
        await navigator.clipboard.writeText(link.invite_link);
        copiedId.value = link.id;
        toast.success('نُسخ رابط الدعوة');
        window.setTimeout(() => {
            if (copiedId.value === link.id) copiedId.value = null;
        }, 1800);
    } catch {
        toast.error('تعذّر نسخ الرابط');
    }
}

function statusClass(status: InviteLinkRow['status']): string {
    if (status === 'available')
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300';
    if (status === 'used') return 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300';
    return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300';
}
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-border bg-card" aria-labelledby="links-heading">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-4 py-4 sm:px-5">
            <div>
                <h2 id="links-heading" class="font-semibold">{{ searching ? 'الروابط المطابقة' : 'دليل الروابط' }}</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ searching ? 'روابط تطابق العنوان أو المنشئ أو المجموعة أو أحد المنضمين.' : 'أحدث ١٠٠ رابط مع حالته ومنشئه واستخدامه.' }}
                </p>
            </div>
            <Badge v-if="links.length" variant="secondary" dir="ltr" class="tabular-nums">{{ formatNumber(links.length) }}</Badge>
        </div>

        <EmptyState
            v-if="!links.length"
            :icon="searching ? SearchX : Link2"
            :title="searching ? 'لا يوجد رابط مطابق' : 'لا توجد روابط مسجّلة'"
            :description="
                searching ? 'تأكد من الرابط أو جرّب جزءًا منه، أو وسّع الفترة والمجموعة.' : 'ستظهر هنا الروابط التي ينشئها أمر «رابط» في تيليجرام.'
            "
            class="m-4"
        />

        <ul v-else class="divide-y divide-border">
            <li v-for="link in links" :key="link.id" class="px-4 py-4 transition-colors hover:bg-muted/30 sm:px-5">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="mt-0.5 hidden size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground sm:flex">
                        <Link2 class="size-4" aria-hidden="true" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge variant="outline" :class="statusClass(link.status)">{{ linkStatusLabels[link.status] }}</Badge>
                            <span v-if="link.link_name" class="truncate text-sm font-medium"
                                ><bdi>{{ link.link_name }}</bdi></span
                            >
                            <span v-if="link.chat_title" class="truncate text-xs text-muted-foreground"
                                ><bdi>{{ link.chat_title }}</bdi></span
                            >
                        </div>

                        <a
                            :href="link.invite_link"
                            target="_blank"
                            rel="noreferrer"
                            dir="ltr"
                            class="mt-2 block truncate text-start font-mono text-sm text-primary underline-offset-4 hover:underline"
                        >
                            {{ link.invite_link }}
                        </a>

                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            <span>
                                أنشأه <bdi class="font-medium text-foreground">{{ link.creator ?? link.creator_telegram_user_id }}</bdi>
                                <span v-if="link.creator_username && link.creator" dir="ltr"> (@{{ link.creator_username }})</span>
                            </span>
                            <span>
                                الاستخدام
                                <span dir="ltr" class="text-foreground tabular-nums"
                                    >{{ formatNumber(link.joins_count)
                                    }}<template v-if="link.member_limit"> / {{ formatNumber(link.member_limit) }}</template></span
                                >
                            </span>
                            <span v-if="link.created_at" :title="formatDateTime(link.created_at)"
                                >أُنشئ {{ formatRelativeTime(link.created_at) }}</span
                            >
                            <span v-if="link.expires_at" :title="formatDateTime(link.expires_at)"
                                >ينتهي {{ formatRelativeTime(link.expires_at) }}</span
                            >
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <Button variant="ghost" size="icon-sm" :aria-label="`نسخ الرابط ${link.invite_link}`" @click="copyLink(link)">
                            <Check v-if="copiedId === link.id" class="text-emerald-600" />
                            <Copy v-else />
                        </Button>
                        <Button as-child variant="ghost" size="icon-sm">
                            <a :href="link.invite_link" target="_blank" rel="noreferrer" :aria-label="`فتح الرابط ${link.invite_link}`">
                                <ExternalLink />
                            </a>
                        </Button>
                    </div>
                </div>
            </li>
        </ul>
    </section>
</template>
