<script setup lang="ts">
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import ApprovalCard from '@/components/manage/assistant/ApprovalCard.vue';
import type { AssistantCard, AssistantDecision, TrackedCard } from '@/components/manage/assistant/types';
import { Button } from '@/components/ui/button';
import { decide as decideChat, send as sendChat, show as showConversation } from '@/routes/manage/assistant';
import { Head, Link } from '@inertiajs/vue3';
import { closesReasoning, type ToolPayload } from '@saad5400/ai-kit/events';
import { readSseStream } from '@saad5400/ai-kit/sse';
import Markdown from '@saad5400/ai-kit/vue/Markdown.vue';
import ThinkingDisclosure from '@saad5400/ai-kit/vue/ThinkingDisclosure.vue';
import ToolChip from '@saad5400/ai-kit/vue/ToolChip.vue';
import { CircleStop, RotateCcw, SendHorizontal, Settings, ShieldCheck, Sparkles } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';

/**
 * The admin assistant chat: the operator copilot whose writes pause the turn
 * for approval. POST /manage/assistant/chat streams the reply as ai-kit SSE
 * frames — reasoning/delta/tool/approval/question/done/error, read with the
 * kit's `readSseStream` — the same transport as the public AssistantPage. A
 * pause renders inline cards with تأكيد/رفض (or an answer box); once every
 * pending card is decided, the batch resumes the SAME turn via
 * POST /manage/assistant/chat/{conversation}/decide and the continuation
 * streams into the thread. Nothing is applied without a decision here.
 *
 * Thinking and tool progress are live-only: the server never persists them,
 * so a rehydrated thread shows neither rather than inventing them.
 */

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    assistant: {
        enabled: boolean;
        disabledReason: string | null;
    };
}>();

/** One `tool` chip, keyed by the wire event's tool-call id. */
interface StreamedTool {
    id: string;
    name: string;
    status: ToolPayload['status'];
    successful?: boolean;
}

interface ChatMessage {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    /** Accumulated `reasoning` deltas — live-only, never rehydrated. */
    reasoning: string;
    /** Whether reasoning is still arriving; false collapses the block. */
    reasoningLive: boolean;
    tools: StreamedTool[];
    cards: TrackedCard[];
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

/** A fresh assistant bubble to stream a turn into. */
const newReply = (): ChatMessage => ({
    id: nextLocalId++,
    role: 'assistant',
    content: '',
    reasoning: '',
    reasoningLive: false,
    tools: [],
    cards: [],
    streaming: true,
});

/** While a pause waits for decisions, the composer holds — a new prompt cannot pre-empt the paused turn. */
const hasPendingCards = computed(() => messages.value.some((message) => message.cards.some((tracked) => tracked.status === 'pending')));

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

const trackPending = (card: AssistantCard): TrackedCard => ({ card, status: 'pending', decision: null });

/** A streaming bubble with nothing in it yet still shows the typing dots. */
const isBubbleEmpty = (message: ChatMessage): boolean =>
    message.streaming === true && message.content === '' && message.reasoning === '' && message.tools.length === 0 && message.cards.length === 0;

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
            messages: { role: string; content: string }[];
            pending_approvals: AssistantCard[];
        };

        conversationId.value = storedId;
        messages.value = payload.messages
            .filter((message) => message.role === 'user' || message.role === 'assistant')
            .map((message) => ({
                id: nextLocalId++,
                role: message.role as 'user' | 'assistant',
                content: message.content,
                reasoning: '',
                reasoningLive: false,
                tools: [],
                cards: [],
            }));

        // A paused turn's undecided cards repaint on the last assistant
        // bubble (a pause always ends the stored thread on one).
        if (payload.pending_approvals.length > 0) {
            let target = [...messages.value].reverse().find((message) => message.role === 'assistant');

            if (!target) {
                target = { ...newReply(), streaming: false };
                messages.value.push(target);
            }

            target.cards = payload.pending_approvals.map(trackPending);
        }

        await scrollToBottom();
    } catch {
        // Network hiccup while restoring history: start fresh, keep the id for next reload.
    } finally {
        isRehydrating.value = false;
    }
};

/**
 * Upsert a tool chip by its call id: `running` creates it, the `done` for
 * the same id settles it in place rather than appending a second chip.
 */
const upsertTool = (reply: ChatMessage, payload: ToolPayload): void => {
    const chip = reply.tools.find((tool) => tool.id === payload.id);

    if (chip) {
        chip.status = payload.status;
        chip.successful = payload.successful;

        return;
    }

    reply.tools.push({ id: payload.id, name: payload.name, status: payload.status, successful: payload.successful });
};

const handleSseEvent = (event: string, data: Record<string, unknown>, reply: ChatMessage): void => {
    // The wire brackets nothing: any of delta/tool/done/error closes an open
    // thinking block, and a later `reasoning` reopens it.
    if (closesReasoning(event)) {
        reply.reasoningLive = false;
    }

    if (event === 'reasoning' && typeof data.text === 'string') {
        reply.reasoning += data.text;
        reply.reasoningLive = true;
        void scrollToBottom();
    } else if (event === 'delta' && typeof data.text === 'string') {
        reply.content += data.text;
        void scrollToBottom();
    } else if (event === 'tool' && typeof data.id === 'string' && typeof data.name === 'string') {
        upsertTool(reply, data as unknown as ToolPayload);
        void scrollToBottom();
    } else if ((event === 'approval' || event === 'question') && typeof data.id === 'string') {
        // A paused call emitted `running` and will never emit `done` on this
        // stream — its card IS the chip's outcome, so fold the spinner in.
        reply.tools = reply.tools.filter((tool) => tool.id !== data.id);
        reply.cards.push(trackPending(data as unknown as AssistantCard));
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

/**
 * POST a body to an SSE endpoint and fold the frames into `liveReply` —
 * shared by the first send and every decision resume, which speak the same
 * stream contract.
 */
const streamInto = async (url: string, body: Record<string, unknown>, liveReply: ChatMessage): Promise<void> => {
    isStreaming.value = true;
    abortController = new AbortController();
    await scrollToBottom();

    try {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
                Accept: 'text/event-stream',
            },
            body: JSON.stringify(body),
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

        if (!response.body) {
            liveReply.failed = true;
            errorBanner.value = 'حدث خطأ أثناء قراءة الرد. حاول مرة أخرى.';
            return;
        }

        await readSseStream(response, (event, data) => handleSseEvent(event, (data ?? {}) as Record<string, unknown>, liveReply), {
            signal: abortController.signal,
        });
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            liveReply.failed = liveReply.content === '';
            errorBanner.value = 'تعذر الاتصال بالخادم. تأكد من اتصالك ثم أعد المحاولة.';
        }
    } finally {
        liveReply.streaming = false;
        liveReply.reasoningLive = false;

        // Stopping the turn (or losing the connection) can leave a chip
        // mid-flight; a spinner nothing will ever resolve is worse than no chip.
        liveReply.tools = liveReply.tools.filter((tool) => tool.status === 'done');

        if (liveReply.failed && liveReply.content === '' && liveReply.cards.length === 0) {
            messages.value = messages.value.filter((item) => item.id !== liveReply.id);
        }

        isStreaming.value = false;
        abortController = undefined;
        await scrollToBottom();
    }
};

const sendMessage = async (): Promise<void> => {
    const message = draft.value.trim();

    if (message === '' || message.length > MAX_MESSAGE_LENGTH || isStreaming.value || hasPendingCards.value || !props.assistant.enabled) {
        return;
    }

    errorBanner.value = null;

    messages.value.push({
        id: nextLocalId++,
        role: 'user',
        content: message,
        reasoning: '',
        reasoningLive: false,
        tools: [],
        cards: [],
    });

    messages.value.push(newReply());
    const liveReply = messages.value[messages.value.length - 1];

    draft.value = '';

    await streamInto(
        sendChat.url(),
        {
            message,
            ...(conversationId.value ? { conversation_id: conversationId.value } : {}),
        },
        liveReply,
    );
};

/**
 * Record one card's decision; once every pending card in the thread is
 * decided, resume the paused turn with the whole batch (the server rejects
 * partial batches, because an undecided call would be silently rejected).
 */
const onCardDecision = async (tracked: TrackedCard, decision: AssistantDecision): Promise<void> => {
    if (tracked.status !== 'pending' || isStreaming.value || !conversationId.value) {
        return;
    }

    tracked.decision = decision;
    tracked.status = 'decided';

    const undecided = messages.value.flatMap((message) => message.cards).filter((item) => item.status === 'pending');

    if (undecided.length > 0) {
        return;
    }

    const batch = messages.value.flatMap((message) => message.cards).filter((item) => item.status === 'decided');
    const decisions = Object.fromEntries(batch.map((item) => [item.card.id, item.decision]));

    batch.forEach((item) => {
        item.status = 'submitted';
    });

    errorBanner.value = null;

    messages.value.push(newReply());
    const liveReply = messages.value[messages.value.length - 1];

    await streamInto(decideChat.url(conversationId.value), { decisions }, liveReply);
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
                    <div class="assistant-bubble max-w-[85%] space-y-3 rounded-2xl rounded-ss-sm bg-muted px-4 py-2.5">
                        <div v-if="isBubbleEmpty(message)" class="flex items-center gap-1 py-1" aria-label="المساعد يكتب الآن">
                            <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 0ms" />
                            <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 150ms" />
                            <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 300ms" />
                        </div>

                        <!-- Reasoning is live-only; remounting on `reasoningLive` is what collapses it once the turn moves on. -->
                        <ThinkingDisclosure
                            v-if="message.reasoning !== ''"
                            :key="`thinking-${message.id}-${message.reasoningLive}`"
                            :text="message.reasoning"
                            :live="message.reasoningLive"
                            :label="message.reasoningLive ? 'يفكّر' : 'خطوات التفكير'"
                        />

                        <div v-if="message.tools.length > 0" class="flex flex-wrap gap-1.5">
                            <ToolChip
                                v-for="tool in message.tools"
                                :key="tool.id"
                                :name="tool.name"
                                :status="tool.status"
                                :successful="tool.successful"
                            />
                        </div>

                        <Markdown
                            v-if="message.content !== ''"
                            class="text-sm leading-relaxed"
                            :source="message.content"
                            :live="message.streaming === true"
                        />

                        <div v-if="message.cards.length > 0" class="space-y-2">
                            <ApprovalCard
                                v-for="tracked in message.cards"
                                :key="tracked.card.id"
                                :tracked="tracked"
                                @decide="(decision) => onCardDecision(tracked, decision)"
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
                    :placeholder="hasPendingCards ? 'قرر البطاقات المعلّقة أولاً ليكمل المساعد رده…' : 'اطلب تعديلاً على الصفحات أو الإعدادات…'"
                    aria-label="نص الرسالة"
                    class="max-h-40 min-h-10 flex-1 resize-y rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring"
                    :disabled="isStreaming || hasPendingCards"
                    @keydown="onComposerKeydown"
                />

                <Button v-if="isStreaming" variant="outline" size="icon" aria-label="إيقاف التوليد" @click="stopStreaming">
                    <CircleStop class="size-4 text-destructive" />
                </Button>
                <Button v-else size="icon" aria-label="إرسال الرسالة" :disabled="draft.trim() === '' || hasPendingCards" @click="sendMessage">
                    <SendHorizontal class="size-4 -scale-x-100" />
                </Button>
            </div>

            <p class="mt-1.5 text-[11px] text-muted-foreground">Enter للإرسال، Shift+Enter لسطر جديد — حتى ٢٠٠٠ حرف.</p>
        </div>
    </div>
</template>

<style scoped>
/*
 * The assistant markdown itself comes from ai-kit's prose sheet (imported in
 * app.css); only the bubble-local token overrides live here. The bubble is
 * `bg-muted`, so inline code and fenced blocks need the page background to
 * read as raised rather than vanishing into it.
 */
.assistant-bubble {
    --ai-kit-muted-bg: var(--background);
    --ai-kit-chip-bg: var(--background);
    --ai-kit-chip-color: var(--muted-foreground);
    --ai-kit-chip-failed-color: var(--destructive);
    --ai-kit-thinking-border: var(--border);
}
</style>
