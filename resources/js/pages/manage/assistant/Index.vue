<script setup lang="ts">
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import ProposalCard from '@/components/manage/assistant/ProposalCard.vue';
import type { AssistantProposal } from '@/components/manage/assistant/types';
import { Button } from '@/components/ui/button';
import { renderMarkdown } from '@/lib/markdown';
import { send as sendChat, show as showConversation } from '@/routes/manage/assistant';
import { Head, Link } from '@inertiajs/vue3';
import { CircleStop, RotateCcw, SendHorizontal, Settings, ShieldCheck, Sparkles } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';

/**
 * The admin assistant chat: the operator copilot that inspects pages and
 * settings and PROPOSES changes. POST /manage/assistant/chat streams the
 * reply as SSE frames (delta/proposal/done/error) parsed off a fetch
 * ReadableStream — the same transport as the public AssistantPage — and each
 * `proposal` event renders an inline action card with تأكيد/رفض buttons.
 * Nothing is applied without a confirmation click.
 */

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    assistant: {
        enabled: boolean;
        disabledReason: string | null;
    };
}>();

interface ChatMessage {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    proposals: AssistantProposal[];
    streaming?: boolean;
    failed?: boolean;
}

const CONVERSATION_STORAGE_KEY = 'manage-assistant-conversation-id';
const MAX_MESSAGE_LENGTH = 2000;

/** First-open teaching prompts: what the assistant is actually for. */
const examplePrompts = ['رتب صفحات قسم اللوائح حسب الأحدث', 'فعّل بحث الذكاء الاصطناعي', 'ما الصفحات التي لم تُحدَّث منذ سنة؟'];

const messages = ref<ChatMessage[]>([]);
const draft = ref('');
const isStreaming = ref(false);
const isRehydrating = ref(false);
const errorBanner = ref<string | null>(null);
const conversationId = ref<string | null>(null);

const messagesContainer = useTemplateRef<HTMLDivElement>('messagesContainer');
const draftInput = useTemplateRef<HTMLTextAreaElement>('draftInput');

let nextLocalId = 1;
let abortController: AbortController | undefined;

const xsrfToken = (): string => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

const scrollToBottom = async (): Promise<void> => {
    await nextTick();
    messagesContainer.value?.scrollTo({ top: messagesContainer.value.scrollHeight });
};

const readJsonMessage = async (response: Response): Promise<string | null> => {
    try {
        const payload = (await response.json()) as { message?: string; errors?: Record<string, string[]> };

        return Object.values(payload.errors ?? {})[0]?.[0] ?? payload.message ?? null;
    } catch {
        return null;
    }
};

/** Rehydrate a conversation persisted across reloads; 404 means a clean slate. */
const rehydrateConversation = async (): Promise<void> => {
    const storedId = sessionStorage.getItem(CONVERSATION_STORAGE_KEY);

    if (!storedId || !props.assistant.enabled) {
        return;
    }

    isRehydrating.value = true;

    try {
        const response = await fetch(showConversation.url(storedId), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            sessionStorage.removeItem(CONVERSATION_STORAGE_KEY);
            return;
        }

        const payload = (await response.json()) as {
            messages: { role: string; content: string; proposals: AssistantProposal[] }[];
        };

        conversationId.value = storedId;
        messages.value = payload.messages
            .filter((message) => message.role === 'user' || message.role === 'assistant')
            .map((message) => ({
                id: nextLocalId++,
                role: message.role as 'user' | 'assistant',
                content: message.content,
                proposals: message.proposals ?? [],
            }));

        await scrollToBottom();
    } catch {
        // Network hiccup while restoring history: start fresh, keep the id for next reload.
    } finally {
        isRehydrating.value = false;
    }
};

/** Parse one SSE frame ("event: name\ndata: {...}") into its name and payload. */
const parseSseFrame = (frame: string): { event: string; data: Record<string, unknown> } | null => {
    let event = 'message';
    const dataLines: string[] = [];

    for (const line of frame.split('\n')) {
        if (line.startsWith('event:')) {
            event = line.slice(6).trim();
        } else if (line.startsWith('data:')) {
            dataLines.push(line.slice(5).trim());
        }
    }

    if (dataLines.length === 0) {
        return null;
    }

    try {
        return { event, data: JSON.parse(dataLines.join('\n')) as Record<string, unknown> };
    } catch {
        return null;
    }
};

const handleSseEvent = (event: string, data: Record<string, unknown>, reply: ChatMessage): void => {
    if (event === 'delta' && typeof data.text === 'string') {
        reply.content += data.text;
        void scrollToBottom();
    } else if (event === 'proposal' && typeof data.id === 'string') {
        reply.proposals.push(data as unknown as AssistantProposal);
        void scrollToBottom();
    } else if (event === 'done') {
        if (typeof data.conversation_id === 'string' && data.conversation_id !== '') {
            conversationId.value = data.conversation_id;
            sessionStorage.setItem(CONVERSATION_STORAGE_KEY, data.conversation_id);
        }
    } else if (event === 'error') {
        reply.failed = true;
        errorBanner.value = typeof data.message === 'string' ? data.message : 'حدث خطأ أثناء توليد الرد. حاول مرة أخرى.';
    }
};

const sendMessage = async (): Promise<void> => {
    const message = draft.value.trim();

    if (message === '' || message.length > MAX_MESSAGE_LENGTH || isStreaming.value || !props.assistant.enabled) {
        return;
    }

    errorBanner.value = null;

    messages.value.push({ id: nextLocalId++, role: 'user', content: message, proposals: [] });

    const reply: ChatMessage = { id: nextLocalId++, role: 'assistant', content: '', proposals: [], streaming: true };
    messages.value.push(reply);
    const liveReply = messages.value[messages.value.length - 1];

    draft.value = '';
    isStreaming.value = true;
    abortController = new AbortController();
    await scrollToBottom();

    try {
        const response = await fetch(sendChat.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
                Accept: 'text/event-stream',
            },
            body: JSON.stringify({
                message,
                ...(conversationId.value ? { conversation_id: conversationId.value } : {}),
            }),
            signal: abortController.signal,
        });

        const contentType = response.headers.get('Content-Type') ?? '';

        if (!contentType.includes('text/event-stream')) {
            const serverMessage = await readJsonMessage(response);

            liveReply.failed = true;

            if (response.status === 429) {
                errorBanner.value = serverMessage ?? 'محاولات كثيرة خلال وقت قصير، انتظر دقيقة ثم أعد المحاولة.';
            } else {
                errorBanner.value = serverMessage ?? 'حدث خطأ أثناء إرسال الرسالة. حاول مرة أخرى.';
            }

            return;
        }

        const reader = response.body?.getReader();

        if (!reader) {
            liveReply.failed = true;
            errorBanner.value = 'حدث خطأ أثناء قراءة الرد. حاول مرة أخرى.';
            return;
        }

        const decoder = new TextDecoder();
        let buffer = '';

        for (;;) {
            const { done, value } = await reader.read();

            if (done) {
                break;
            }

            buffer += decoder.decode(value, { stream: true });

            let separatorIndex = buffer.indexOf('\n\n');
            while (separatorIndex !== -1) {
                const frame = parseSseFrame(buffer.slice(0, separatorIndex));
                buffer = buffer.slice(separatorIndex + 2);

                if (frame) {
                    handleSseEvent(frame.event, frame.data, liveReply);
                }

                separatorIndex = buffer.indexOf('\n\n');
            }
        }
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            liveReply.failed = liveReply.content === '';
            errorBanner.value = 'تعذر الاتصال بالخادم. تأكد من اتصالك ثم أعد المحاولة.';
        }
    } finally {
        liveReply.streaming = false;

        if (liveReply.failed && liveReply.content === '' && liveReply.proposals.length === 0) {
            messages.value = messages.value.filter((item) => item.id !== liveReply.id);
        }

        isStreaming.value = false;
        abortController = undefined;
        await scrollToBottom();
    }
};

const stopStreaming = (): void => {
    abortController?.abort();
};

const startNewConversation = (): void => {
    if (isStreaming.value) {
        stopStreaming();
    }

    sessionStorage.removeItem(CONVERSATION_STORAGE_KEY);
    conversationId.value = null;
    messages.value = [];
    errorBanner.value = null;
};

const useExamplePrompt = (prompt: string): void => {
    draft.value = prompt;
    draftInput.value?.focus();
};

const onProposalUpdated = (message: ChatMessage, updated: AssistantProposal): void => {
    const index = message.proposals.findIndex((proposal) => proposal.id === updated.id);

    if (index !== -1) {
        message.proposals[index] = updated;
    }
};

const onComposerKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        void sendMessage();
    }
};

onMounted(() => {
    void rehydrateConversation();
    draftInput.value?.focus();
});

onBeforeUnmount(() => abortController?.abort());
</script>

<template>
    <Head title="المساعد الإداري" />
    <PageHeader title="المساعد الإداري" description="ينظّم الصفحات ويضبط الإعدادات — كل تغيير يقترحه يتطلب تأكيدك قبل التنفيذ" />

    <!-- Disabled state that teaches how to enable (disabled-with-reason). -->
    <div
        v-if="!assistant.enabled"
        class="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border px-6 py-16 text-center"
    >
        <div class="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <Sparkles class="size-6" />
        </div>
        <p class="font-medium">المساعد الإداري معطل</p>
        <p class="max-w-md text-sm text-muted-foreground">{{ assistant.disabledReason ?? 'المساعد الإداري غير متاح حالياً.' }}</p>
        <Button as-child variant="outline" class="mt-2 gap-1.5">
            <Link href="/manage/settings">
                <Settings class="size-4" />
                فتح الإعدادات
            </Link>
        </Button>
    </div>

    <div v-else class="flex flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm" style="min-height: 65dvh">
        <!-- Conversation header -->
        <div class="flex items-center justify-between gap-2 border-b border-border px-4 py-2">
            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                <ShieldCheck class="size-4 text-emerald-600 dark:text-emerald-400" />
                لا يُنفَّذ أي تغيير إلا بعد ضغطك «تأكيد» على بطاقته.
            </div>
            <Button v-if="messages.length > 0" variant="ghost" size="sm" class="gap-1.5 text-muted-foreground" @click="startNewConversation">
                <RotateCcw class="size-3.5" />
                محادثة جديدة
            </Button>
        </div>

        <!-- Messages -->
        <div ref="messagesContainer" class="flex-1 space-y-4 overflow-y-auto p-4" aria-live="polite">
            <div v-if="isRehydrating" class="flex flex-col gap-3" aria-hidden="true">
                <div class="h-10 w-2/3 animate-pulse self-start rounded-2xl bg-muted" />
                <div class="h-16 w-3/4 animate-pulse self-end rounded-2xl bg-muted/70" />
            </div>

            <!-- Empty state that teaches what the assistant can do. -->
            <div v-else-if="messages.length === 0" class="flex h-full flex-col items-center justify-center gap-3 py-12 text-center">
                <Sparkles class="size-8 text-amber-500" />
                <p class="font-medium">اطلب تنظيم الصفحات أو ضبط الإعدادات</p>
                <p class="max-w-md text-sm text-muted-foreground">
                    يطّلع المساعد على شجرة الصفحات والإعدادات ويقترح التغييرات، وأنت من يؤكدها. جرّب مثلاً:
                </p>
                <div class="mt-1 flex flex-wrap items-center justify-center gap-2">
                    <button
                        v-for="prompt in examplePrompts"
                        :key="prompt"
                        type="button"
                        class="rounded-full border border-border bg-background px-3 py-1.5 text-sm text-muted-foreground transition hover:bg-accent hover:text-accent-foreground"
                        @click="useExamplePrompt(prompt)"
                    >
                        {{ prompt }}
                    </button>
                </div>
            </div>

            <template v-for="message in messages" :key="message.id">
                <!-- User bubble -->
                <div v-if="message.role === 'user'" class="flex justify-end">
                    <div class="max-w-[85%] rounded-2xl rounded-se-sm bg-primary px-4 py-2.5 text-sm whitespace-pre-wrap text-primary-foreground">
                        {{ message.content }}
                    </div>
                </div>

                <!-- Assistant bubble -->
                <div v-else class="flex justify-start">
                    <div class="max-w-[85%] space-y-3 rounded-2xl rounded-ss-sm bg-muted px-4 py-2.5">
                        <div
                            v-if="message.streaming && message.content === '' && message.proposals.length === 0"
                            class="flex items-center gap-1 py-1"
                            aria-label="المساعد يكتب الآن"
                        >
                            <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 0ms" />
                            <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 150ms" />
                            <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 300ms" />
                        </div>

                        <!-- eslint-disable-next-line vue/no-v-html -- renderMarkdown escapes + DOMPurify-sanitizes model output -->
                        <div
                            v-else-if="message.content !== ''"
                            class="assistant-markdown text-sm leading-relaxed"
                            v-html="renderMarkdown(message.content)"
                        />

                        <div v-if="message.proposals.length > 0" class="space-y-2">
                            <ProposalCard
                                v-for="proposal in message.proposals"
                                :key="proposal.id"
                                :proposal="proposal"
                                @updated="(updated) => onProposalUpdated(message, updated)"
                            />
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Error banner -->
        <div v-if="errorBanner" class="border-t border-border bg-destructive/10 px-4 py-2 text-sm text-destructive">
            {{ errorBanner }}
        </div>

        <!-- Composer -->
        <div class="border-t border-border p-3">
            <div class="flex items-end gap-2">
                <textarea
                    ref="draftInput"
                    v-model="draft"
                    dir="rtl"
                    rows="1"
                    :maxlength="MAX_MESSAGE_LENGTH"
                    placeholder="اطلب تعديلاً على الصفحات أو الإعدادات…"
                    aria-label="نص الرسالة"
                    class="max-h-40 min-h-10 flex-1 resize-y rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring"
                    :disabled="isStreaming"
                    @keydown="onComposerKeydown"
                />

                <Button v-if="isStreaming" variant="outline" size="icon" aria-label="إيقاف التوليد" @click="stopStreaming">
                    <CircleStop class="size-4 text-destructive" />
                </Button>
                <Button v-else size="icon" aria-label="إرسال الرسالة" :disabled="draft.trim() === ''" @click="sendMessage">
                    <SendHorizontal class="size-4 -scale-x-100" />
                </Button>
            </div>

            <p class="mt-1.5 text-[11px] text-muted-foreground">Enter للإرسال، Shift+Enter لسطر جديد — حتى ٢٠٠٠ حرف.</p>
        </div>
    </div>
</template>

<style scoped>
.assistant-markdown :deep(p) {
    margin-block: 0.375rem;
}

.assistant-markdown :deep(p:first-child) {
    margin-top: 0;
}

.assistant-markdown :deep(p:last-child) {
    margin-bottom: 0;
}

.assistant-markdown :deep(ul),
.assistant-markdown :deep(ol) {
    margin-block: 0.375rem;
    padding-inline-start: 1.25rem;
}

.assistant-markdown :deep(ul) {
    list-style: disc;
}

.assistant-markdown :deep(ol) {
    list-style: decimal;
}

.assistant-markdown :deep(h2),
.assistant-markdown :deep(h3),
.assistant-markdown :deep(h4) {
    margin-block: 0.625rem 0.25rem;
    font-weight: 600;
}

.assistant-markdown :deep(code) {
    border-radius: 0.25rem;
    background: var(--background);
    padding: 0.125rem 0.375rem;
    font-size: 0.8125em;
    direction: ltr;
    unicode-bidi: embed;
}

.assistant-markdown :deep(pre) {
    margin-block: 0.5rem;
    overflow-x: auto;
    border-radius: 0.5rem;
    background: var(--background);
    padding: 0.75rem;
    direction: ltr;
    text-align: left;
}

.assistant-markdown :deep(pre code) {
    padding: 0;
    background: transparent;
}

.assistant-markdown :deep(a) {
    color: var(--primary);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.assistant-markdown :deep(blockquote) {
    margin-block: 0.5rem;
    border-inline-start: 3px solid var(--border);
    padding-inline-start: 0.75rem;
    color: var(--muted-foreground);
}

/* Tables scroll inside the bubble on narrow screens instead of overflowing it. */
.assistant-markdown :deep(table) {
    display: block;
    width: max-content;
    max-width: 100%;
    overflow-x: auto;
    margin-block: 0.5rem;
    border-collapse: collapse;
    font-size: 0.8125rem;
    font-variant-numeric: tabular-nums;
}

.assistant-markdown :deep(th),
.assistant-markdown :deep(td) {
    border: 1px solid var(--border);
    padding: 0.375rem 0.625rem;
    text-align: start;
    vertical-align: top;
}

.assistant-markdown :deep(th) {
    background: var(--background);
    font-weight: 600;
}
</style>
