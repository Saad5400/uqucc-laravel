<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { formatDateTime, formatRelativeTime } from '@/lib/formatters';
import { Head, router } from '@inertiajs/vue3';
import { Bot, EllipsisVertical, RefreshCw, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

/** One Telegram chat row — created by the bot's /ai_on and /ai_off commands. */
interface ChatRow {
    id: number;
    chat_id: string;
    title: string | null;
    type: string | null;
    ai_enabled: boolean;
    enabled_by: string | null;
    has_conversation: boolean;
    updated_at: string | null;
}

const props = defineProps<{
    chats: ChatRow[];
}>();

const typeLabels: Record<string, string> = {
    private: 'خاص',
    group: 'مجموعة',
    supergroup: 'مجموعة كبيرة',
    channel: 'قناة',
};

/** Sentinel for "no filter" — reka-ui selects reserve the empty string. */
const ALL = 'all';

const search = ref('');
const enabledFilter = ref<string>(ALL);

const filteredChats = computed(() => {
    const query = search.value.trim();

    return props.chats.filter((chat) => {
        if (enabledFilter.value !== ALL && chat.ai_enabled !== (enabledFilter.value === 'enabled')) {
            return false;
        }

        return query === '' || (chat.title ?? '').includes(query) || chat.chat_id.includes(query);
    });
});

function chatLabel(chat: ChatRow): string {
    return chat.title ?? 'بدون اسم';
}

/* ------------------------------------------------------------------ */
/* Toggle (saves immediately — the switch itself is the feedback)      */
/* ------------------------------------------------------------------ */

const togglingId = ref<number | null>(null);

function toggleAi(chat: ChatRow, value: boolean): void {
    togglingId.value = chat.id;

    router.put(
        `/manage/telegram-chats/${chat.id}`,
        { ai_enabled: value },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                togglingId.value = null;
            },
        },
    );
}

/* ------------------------------------------------------------------ */
/* Reset conversation + delete (each behind a ConfirmDialog)           */
/* ------------------------------------------------------------------ */

type ConfirmableAction = 'reset' | 'delete';

const confirmingAction = ref<ConfirmableAction | null>(null);
const targetChat = ref<ChatRow | null>(null);
const processing = ref(false);

function confirmAction(action: ConfirmableAction, chat: ChatRow): void {
    targetChat.value = chat;
    confirmingAction.value = action;
}

function runConfirmedAction(): void {
    if (!targetChat.value || !confirmingAction.value) {
        return;
    }

    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            confirmingAction.value = null;
        },
        onFinish: () => {
            processing.value = false;
        },
    };

    processing.value = true;

    if (confirmingAction.value === 'delete') {
        router.delete(`/manage/telegram-chats/${targetChat.value.id}`, options);
    } else {
        router.post(`/manage/telegram-chats/${targetChat.value.id}/reset-conversation`, {}, options);
    }
}
</script>

<template>
    <Head title="ذكاء بوت التليجرام" />
    <PageHeader
        title="ذكاء بوت التليجرام"
        description="المحادثات التي فعّلت المساعد الذكي من داخل تيليجرام — يمكنك تفعيله أو إيقافه لأي محادثة من هنا"
    />

    <div class="space-y-4">
        <div v-if="chats.length" class="flex flex-wrap items-center gap-2">
            <Input v-model="search" type="search" placeholder="ابحث بالاسم أو المعرّف…" class="max-w-xs" aria-label="البحث في المحادثات" />

            <Select v-model="enabledFilter">
                <SelectTrigger class="w-40" aria-label="تصفية بحالة المساعد">
                    <SelectValue placeholder="المساعد الذكي" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">الكل</SelectItem>
                    <SelectItem value="enabled">مفعل</SelectItem>
                    <SelectItem value="disabled">موقوف</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <EmptyState
            v-if="!chats.length"
            :icon="Bot"
            title="لا توجد محادثات بعد"
            description="تُنشأ الصفوف هنا عندما تستخدم محادثات تيليجرام أمري ‎/ai_on و ‎/ai_off لتفعيل المساعد أو إيقافه."
        />

        <p v-else-if="!filteredChats.length" class="py-8 text-center text-sm text-muted-foreground">لا نتائج مطابقة للتصفية الحالية.</p>

        <ul v-else class="overflow-hidden rounded-lg border border-border">
            <li v-for="chat in filteredChats" :key="chat.id" class="flex items-center gap-3 border-b border-border p-3 last:border-b-0">
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-medium">{{ chatLabel(chat) }}</span>
                        <Badge v-if="chat.type" variant="secondary">{{ typeLabels[chat.type] ?? chat.type }}</Badge>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                        <span dir="ltr" class="tabular-nums">{{ chat.chat_id }}</span>
                        <span v-if="chat.enabled_by"
                            >فعّله <span dir="ltr">{{ chat.enabled_by }}</span></span
                        >
                        <span v-if="chat.updated_at" :title="formatDateTime(chat.updated_at)"
                            >آخر تحديث {{ formatRelativeTime(chat.updated_at) }}</span
                        >
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Switch
                        :model-value="chat.ai_enabled"
                        :disabled="togglingId === chat.id"
                        :aria-label="`المساعد الذكي في ${chatLabel(chat)}`"
                        @update:model-value="(value) => toggleAi(chat, value === true)"
                    />
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="ghost" size="icon" :aria-label="`إجراءات ${chatLabel(chat)}`">
                                <EllipsisVertical />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem v-if="chat.has_conversation" @select="confirmAction('reset', chat)">
                                <RefreshCw />
                                محادثة جديدة
                            </DropdownMenuItem>
                            <DropdownMenuSeparator v-if="chat.has_conversation" />
                            <DropdownMenuItem variant="destructive" @select="confirmAction('delete', chat)">
                                <Trash2 />
                                حذف
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </li>
        </ul>

        <ConfirmDialog
            :open="confirmingAction === 'reset'"
            title="بدء محادثة جديدة"
            confirm-label="بدء محادثة جديدة"
            :processing="processing"
            @confirm="runConfirmedAction"
            @update:open="(value) => (confirmingAction = value ? 'reset' : null)"
        >
            سيبدأ المساعد محادثة جديدة في هذه الدردشة وينسى سياق المحادثة الحالية.
        </ConfirmDialog>

        <ConfirmDialog
            :open="confirmingAction === 'delete'"
            title="حذف المحادثة"
            destructive
            confirm-label="حذف"
            :processing="processing"
            @confirm="runConfirmedAction"
            @update:open="(value) => (confirmingAction = value ? 'delete' : null)"
        >
            <template v-if="targetChat">
                سيُحذف صف المحادثة «{{ chatLabel(targetChat) }}» وسيتوقف المساعد فيها حتى تعيد المحادثة تفعيله بأمر ‎/ai_on.
            </template>
        </ConfirmDialog>
    </div>
</template>
