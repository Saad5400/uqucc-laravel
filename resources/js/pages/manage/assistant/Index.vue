<script setup lang="ts">
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import { asApprovalCard, isQuestionCard, type AssistantApprovalCard, type CardDecision } from '@/components/manage/assistant/types';
import { Button } from '@/components/ui/button';
import {
    cancel as cancelTurn,
    decide as decideChat,
    send as sendChat,
    show as showConversation,
    stream as streamTurn,
} from '@/routes/manage/assistant';
import { Head, Link } from '@inertiajs/vue3';
import type { AiKitCard, ClientDecision, QuestionPayload } from '@saad5400/ai-kit/events';
import { readSseStream } from '@saad5400/ai-kit/sse';
import { createTimeline, groupSegments, type Segment, type SegmentGroup, type Timeline } from '@saad5400/ai-kit/timeline';
import ApprovalCard from '@saad5400/ai-kit/vue/ApprovalCard.vue';
import Markdown from '@saad5400/ai-kit/vue/Markdown.vue';
import ProcessGroup from '@saad5400/ai-kit/vue/ProcessGroup.vue';
import QuestionCard from '@saad5400/ai-kit/vue/QuestionCard.vue';
import {
    BookOpen,
    CircleCheck,
    CircleStop,
    CircleX,
    FileText,
    HelpCircle,
    RotateCcw,
    Send,
    SendHorizontal,
    Settings,
    Settings2,
    ShieldCheck,
    Sparkles,
    TriangleAlert,
    Users,
} from 'lucide-vue-next';
import { computed, markRaw, nextTick, onBeforeUnmount, onMounted, reactive, ref, useTemplateRef, type Component } from 'vue';

/**
 * The admin assistant chat: the operator copilot whose writes pause the turn
 * for approval. POST /manage/assistant/chat streams the reply as ai-kit SSE
 * frames — turn/reasoning/delta/tool/approval/question/done/error, read with
 * the kit's `readSseStream` — the same transport as the public AssistantPage. A
 * pause renders inline cards with تأكيد/رفض (or an answer box); once every
 * pending card is decided, the batch resumes the SAME turn via
 * POST /manage/assistant/chat/{conversation}/decide and the continuation
 * streams into the thread. Nothing is applied without a decision here.
 *
 * Each turn is modelled as an ORDERED segment list (ai-kit's `createTimeline`),
 * not an accumulated text string beside an accumulated reasoning string: the
 * events already arrive chronologically, and only a list can render "thought,
 * called a tool, answered, thought again" in the order it happened. Every SSE
 * event is pushed through the timeline — it ignores the ones it does not own —
 * and `groupSegments` collapses thinking and tool runs into one disclosure
 * while text and decision cards stay top-level where they occurred.
 *
 * ── Resumable turns ── The reply is generated into a durable server-side
 * buffer, so it survives the connection that asked for it. Every frame the
 * server sends carries an `id:` — the buffer sequence number, handed to the
 * reader's third argument — and re-issuing the last one as `?cursor=` is what
 * makes a reconnect resume rather than replay. So a drop (a flaky link, the
 * server's own stream ceiling) climbs a reconnect ladder against `stream`,
 * and a page reload picks the turn back up from the id parked in
 * sessionStorage. Seeing any frame forgives the previous drop, so a long
 * turn never exhausts its retries.
 *
 * That matters most on this surface: a turn that paused for approval, or one
 * whose approved writes are running, is exactly the turn an operator cannot
 * afford to lose to a dropped connection.
 *
 * Because generation no longer rides the request, hanging up does not stop
 * it: the stop button tells the SERVER to stop.
 *
 * Thinking and tool progress are live-only: the server never persists them,
 * so a rehydrated thread replays its stored text and repaints its pending
 * cards, and shows no process steps rather than inventing them.
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
    /** The user's own text; an assistant turn renders from `segments` instead. */
    content: string;
    /** The turn in arrival order — mutated in place by `timeline`. */
    segments: Segment[];
    timeline: Timeline;
    streaming?: boolean;
    failed?: boolean;
}

const CONVERSATION_STORAGE_KEY = 'manage-assistant-conversation-id';
/** The turn still in flight when the page was left, so a reload can resume it. */
const ACTIVE_TURN_STORAGE_KEY = 'manage-assistant-active-turn-id';
/** Reconnect attempts before a turn is given up on — the ladder tops out at 8s a try. */
const MAX_RECONNECTS = 8;
const MAX_MESSAGE_LENGTH = 2000;

/** First-open teaching prompts: what the assistant is actually for. */
const examplePrompts = ['رتب صفحات قسم اللوائح حسب الأحدث', 'فعّل بحث الذكاء الاصطناعي', 'ما الصفحات التي لم تُحدَّث منذ سنة؟'];

/** Icon + heading per action category. */
const categoryMeta: Record<string, { icon: Component; label: string }> = {
    pages: { icon: FileText, label: 'تغيير على الصفحات' },
    settings: { icon: Settings2, label: 'تغيير على الإعدادات' },
    tutors: { icon: Users, label: 'تغيير على المدرّسين' },
    users: { icon: Users, label: 'تغيير على المستخدمين' },
    reviews: { icon: FileText, label: 'إجراء على المراجعات' },
    telegram: { icon: Send, label: 'تغيير على إعدادات تيليجرام' },
    corpus: { icon: BookOpen, label: 'إجراء على قاعدة المعرفة' },
    quiz: { icon: HelpCircle, label: 'إجراء على سؤال اليوم' },
    system: { icon: Settings2, label: 'إجراء على النظام' },
};

const messages = ref<ChatMessage[]>([]);

/**
 * Every card the thread has shown, by tool-call id. Decisions are batched
 * across cards (and across bubbles, when a resumed turn pauses again), so
 * this lives beside the messages rather than inside one of them.
 */
const cardDecisions = reactive<Record<string, CardDecision>>({});

const draft = ref('');
const isStreaming = ref(false);
const isRehydrating = ref(false);
const errorBanner = ref<string | null>(null);
const conversationId = ref<string | null>(null);

const messagesContainer = useTemplateRef<HTMLDivElement>('messagesContainer');
const draftInput = useTemplateRef<HTMLTextAreaElement>('draftInput');

let nextLocalId = 1;
let abortController: AbortController | undefined;

/**
 * The turn currently being read, how far into its buffer this client has got,
 * and whether it has reached a terminal event. `lastSeq` is the cursor every
 * reconnect re-issues; `reconnects` is reset by any frame that lands, so
 * progress forgives the drop that preceded it.
 */
let currentTurn: string | null = null;
let lastSeq = 0;
let reconnects = 0;
let turnFinished = false;

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

/** Whether the admin has walked away from the turn currently being read. */
const readAborted = (): boolean => abortController?.signal.aborted === true;

/** The answer as plain text, for matching a replayed turn against a rehydrated one. */
const answerText = (segments: Segment[]): string =>
    segments
        .filter((segment) => segment.type === 'text')
        .map((segment) => segment.text)
        .join('');

/**
 * A message whose segment list is reactive and handed to the timeline, so
 * the reducer's in-place mutations re-render the bubble.
 */
const newMessage = (role: 'user' | 'assistant', content = ''): ChatMessage => {
    const segments = reactive<Segment[]>([]);

    return { id: nextLocalId++, role, content, segments, timeline: markRaw(createTimeline(segments)) };
};

/** A fresh assistant bubble to stream a turn into. */
const newReply = (): ChatMessage => {
    const reply = newMessage('assistant');
    reply.streaming = true;

    return reply;
};

/** While a pause waits for decisions, the composer holds — a new prompt cannot pre-empt the paused turn. */
const hasPendingCards = computed(() => Object.values(cardDecisions).some((tracked) => tracked.status === 'pending'));

const groupsOf = (message: ChatMessage): SegmentGroup[] => groupSegments(message.segments);

/** The group the model is still writing into: the last one, while it streams. */
const isLiveGroup = (message: ChatMessage, index: number): boolean => message.streaming === true && index === groupsOf(message).length - 1;

const hasText = (message: ChatMessage): boolean => message.segments.some((segment) => segment.type === 'text' && segment.text !== '');

const hasCard = (message: ChatMessage): boolean => message.segments.some((segment) => segment.type === 'card');

/** A streaming bubble with nothing in it yet still shows the typing dots. */
const isBubbleEmpty = (message: ChatMessage): boolean => message.streaming === true && message.segments.length === 0;

const trackCard = (card: AiKitCard): void => {
    cardDecisions[card.id] ??= { status: 'pending', decision: null, answer: null };
};

/**
 * Cards the server reported pending while a resumable turn was outstanding.
 * They are drawn by that turn's replay, and only fall back to being drawn
 * here if the replay never arrives.
 */
let deferredCards: AiKitCard[] = [];

const paintCards = (target: ChatMessage, cards: AiKitCard[]): void => {
    for (const card of cards) {
        target.timeline.push(card.kind === 'question' ? 'question' : 'approval', card);
        trackCard(card);
    }
};

const trackedFor = (card: AiKitCard): CardDecision => cardDecisions[card.id] ?? { status: 'pending', decision: null, answer: null };

const isPending = (card: AiKitCard): boolean => trackedFor(card).status === 'pending';

const categoryOf = (card: AssistantApprovalCard): { icon: Component; label: string } => {
    const base = categoryMeta[card.category] ?? { icon: FileText, label: 'تغيير مقترح' };

    return card.destructive ? { icon: TriangleAlert, label: base.label } : base;
};

/** What an already-decided approval card settled into, for the record. */
const decisionChip = (card: AiKitCard): { icon: Component; label: string; ok: boolean } | null => {
    const decision = trackedFor(card).decision;

    if (decision === null) {
        return null;
    }

    return decision.action === 'reject' ? { icon: CircleX, label: 'مرفوض', ok: false } : { icon: CircleCheck, label: 'تم التأكيد', ok: true };
};

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
            messages: { role: string; content: string }[];
            pending_approvals: AiKitCard[];
        };

        conversationId.value = storedId;

        // Persisted text replays through the timeline as one `delta`, so a
        // restored turn is the same segment model as a live one.
        messages.value = payload.messages
            .filter((message) => message.role === 'user' || message.role === 'assistant')
            .map((message) => {
                const restored = newMessage(message.role as 'user' | 'assistant', message.content);

                if (restored.role === 'assistant') {
                    restored.timeline.push('delta', { text: message.content });
                }

                return restored;
            });

        // A paused turn's undecided cards repaint on the last assistant
        // bubble (a pause always ends the stored thread on one) — UNLESS that
        // turn is still resumable, in which case its own replay paints them in
        // the bubble they actually happened in. Holding them here rather than
        // drawing them twice; `resumeActiveTurn` draws them after all if the
        // replay never lands.
        if (payload.pending_approvals.length > 0) {
            if (sessionStorage.getItem(ACTIVE_TURN_STORAGE_KEY)) {
                deferredCards = payload.pending_approvals;
            } else {
                let target = [...messages.value].reverse().find((message) => message.role === 'assistant');

                if (!target) {
                    target = newMessage('assistant');
                    messages.value.push(target);
                }

                paintCards(target, payload.pending_approvals);
            }
        }

        await scrollToBottom();
    } catch {
        // Network hiccup while restoring history: start fresh, keep the id for next reload.
    } finally {
        isRehydrating.value = false;
    }
};

/**
 * Fold one frame from the kit's reader.
 *
 * `id` is the buffer sequence number the frame was stored under; recording it
 * is the whole of what makes the next reconnect resume instead of replay.
 */
const handleSseEvent = (event: string, data: Record<string, unknown>, reply: ChatMessage, id?: string): void => {
    if (id !== undefined) {
        const seq = Number.parseInt(id, 10);

        if (!Number.isNaN(seq)) {
            lastSeq = seq;
            reconnects = 0;
        }
    }

    // The turn handle, always the first frame. It is not a timeline event —
    // it is the id a reconnect (or a reload) re-issues to find this turn again.
    if (event === 'turn') {
        if (typeof data.id === 'string' && data.id !== '') {
            currentTurn = data.id;
            sessionStorage.setItem(ACTIVE_TURN_STORAGE_KEY, data.id);
        }

        return;
    }

    // Everything else goes through the timeline, which ignores what it does
    // not own — the app-specific events below are handled beside it, not
    // instead.
    reply.timeline.push(event, data);

    if (event === 'approval' || event === 'question') {
        if (typeof data.id === 'string') {
            trackCard(data as unknown as AiKitCard);
        }
    } else if (event === 'done') {
        // `done` and `error` are each terminal on their own (the kit's buffer
        // contract): one or the other ends the turn, never both, so reaching
        // either means there is nothing left to reconnect for.
        turnFinished = true;

        if (typeof data.conversation_id === 'string' && data.conversation_id !== '') {
            conversationId.value = data.conversation_id;
            sessionStorage.setItem(CONVERSATION_STORAGE_KEY, data.conversation_id);
        }
    } else if (event === 'error') {
        turnFinished = true;
        reply.failed = true;
        errorBanner.value = typeof data.message === 'string' ? data.message : 'حدث خطأ أثناء توليد الرد. حاول مرة أخرى.';
    }

    void scrollToBottom();
};

/** Read one SSE response to its end, folding every frame into the live reply. */
const readTurn = async (response: Response, reply: ChatMessage): Promise<void> => {
    await readSseStream(response, (event, data, id) => handleSseEvent(event, (data ?? {}) as Record<string, unknown>, reply, id), {
        signal: abortController?.signal,
    });
};

/**
 * Keep reading the current turn across drops until it reaches a terminal
 * event or the ladder runs out.
 *
 * A read ends three ways: the turn finished (nothing more to do), the server
 * hit its own stream ceiling and hung up mid-turn, or the connection broke.
 * The last two are the same thing to this loop — wait out the backoff and
 * re-issue `?cursor=lastSeq`, so the server replays only what was missed.
 * Only an abort — the admin leaving the page — returns without reconnecting,
 * and it deliberately leaves the resume handle behind for the next load.
 *
 * `immediate` skips the first backoff, for a turn being picked up from
 * storage rather than recovered from a drop.
 */
const followTurn = async (reply: ChatMessage, immediate = false): Promise<void> => {
    let first = immediate;

    while (!turnFinished && currentTurn !== null && !readAborted()) {
        if (!first) {
            if (reconnects >= MAX_RECONNECTS) {
                turnFinished = true;
                reply.failed = !hasText(reply) && !hasCard(reply);
                errorBanner.value = 'انقطع الاتصال بالمساعد. حاول مرة أخرى.';

                return;
            }

            reconnects++;
            await sleep(Math.min(1000 * 2 ** (reconnects - 1), 8000));

            if (readAborted()) {
                return;
            }
        }

        first = false;

        try {
            const response = await fetch(`${streamTurn.url(currentTurn)}?cursor=${lastSeq}`, {
                credentials: 'same-origin',
                headers: { Accept: 'text/event-stream' },
                signal: abortController?.signal,
            });

            if (response.status === 404) {
                // The buffer expired, or never belonged to this admin: there is
                // no turn to resume, so stop rather than climb the ladder. Any
                // cards it left pending survive on the conversation and repaint
                // on the next rehydrate.
                turnFinished = true;
                reply.failed = !hasText(reply) && !hasCard(reply);
                errorBanner.value = 'انتهت صلاحية هذا الرد. أعد إرسال طلبك.';

                return;
            }

            if (!response.ok || !response.body) {
                continue;
            }

            await readTurn(response, reply);
        } catch (error) {
            if ((error as Error).name === 'AbortError') {
                return;
            }
        }
    }
};

/**
 * Settle a turn that is no longer being read: clear the live flags and drop
 * chips nothing will ever resolve.
 *
 * The resume handle is forgotten only for a turn that actually ENDED — one
 * that reached `done` / `error`, expired, or exhausted its retries. A read
 * that stopped because the admin navigated away leaves it in place on
 * purpose: that is precisely the turn the next page load should pick up.
 */
const settleTurn = async (reply: ChatMessage): Promise<void> => {
    reply.streaming = false;

    // Stopping the turn (or losing the connection for good) can leave a chip
    // mid-flight; a spinner nothing will ever resolve is worse than no chip.
    // Spliced in place: the timeline owns this array.
    for (let index = reply.segments.length - 1; index >= 0; index--) {
        const segment = reply.segments[index];

        if (segment.type === 'tool' && segment.status === 'running') {
            reply.segments.splice(index, 1);
        }
    }

    if (reply.failed && !hasText(reply) && !hasCard(reply)) {
        messages.value = messages.value.filter((item) => item.id !== reply.id);
    }

    if (turnFinished) {
        sessionStorage.removeItem(ACTIVE_TURN_STORAGE_KEY);
        currentTurn = null;
    }

    isStreaming.value = false;
    abortController = undefined;
    await scrollToBottom();
};

/** Reset the per-turn transport state before a fresh turn is read. */
const beginTurn = (): void => {
    currentTurn = null;
    lastSeq = 0;
    reconnects = 0;
    turnFinished = false;
    isStreaming.value = true;
    abortController = new AbortController();
};

/**
 * POST a body to an SSE endpoint and fold the frames into `liveReply` —
 * shared by the first send and every decision resume, which speak the same
 * stream contract.
 */
const streamInto = async (url: string, body: Record<string, unknown>, liveReply: ChatMessage): Promise<void> => {
    beginTurn();
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
            signal: abortController?.signal,
        });

        const contentType = response.headers.get('Content-Type') ?? '';

        if (!contentType.includes('text/event-stream')) {
            const serverMessage = await readJsonMessage(response);

            // A refusal (429 burst, 409 stale cards, 422 bad payload, 503
            // disabled) never opened a turn, so there is nothing to resume.
            turnFinished = true;
            liveReply.failed = true;

            if (response.status === 429) {
                errorBanner.value = serverMessage ?? 'محاولات كثيرة خلال وقت قصير، انتظر دقيقة ثم أعد المحاولة.';
            } else {
                errorBanner.value = serverMessage ?? 'حدث خطأ أثناء إرسال الرسالة. حاول مرة أخرى.';
            }

            return;
        }

        if (!response.body) {
            turnFinished = true;
            liveReply.failed = true;
            errorBanner.value = 'حدث خطأ أثناء قراءة الرد. حاول مرة أخرى.';
            return;
        }

        await readTurn(response, liveReply);
    } catch (error) {
        if ((error as Error).name === 'AbortError') {
            return;
        }

        // The POST itself never got a stream open. If it managed to hand back
        // a turn id first, the turn is running server-side and `followTurn`
        // below picks it up; otherwise there is nothing to recover.
        if (currentTurn === null) {
            turnFinished = true;
            liveReply.failed = !hasText(liveReply) && !hasCard(liveReply);
            errorBanner.value = 'تعذر الاتصال بالخادم. تأكد من اتصالك ثم أعد المحاولة.';
        }
    } finally {
        // The POST's own stream ended. Unless the turn reached a terminal
        // event, it is still generating server-side — reconnect and follow it
        // rather than abandoning a turn whose writes may still be running.
        await followTurn(liveReply);
        await settleTurn(liveReply);
    }
};

const sendMessage = async (): Promise<void> => {
    const message = draft.value.trim();

    if (message === '' || message.length > MAX_MESSAGE_LENGTH || isStreaming.value || hasPendingCards.value || !props.assistant.enabled) {
        return;
    }

    errorBanner.value = null;

    messages.value.push(newMessage('user', message));
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
const onCardDecision = async (card: AiKitCard, decision: ClientDecision, answer: string | null = null): Promise<void> => {
    const tracked = cardDecisions[card.id];

    if (!tracked || tracked.status !== 'pending' || isStreaming.value || !conversationId.value) {
        return;
    }

    tracked.decision = decision;
    tracked.answer = answer;
    tracked.status = 'decided';

    if (Object.values(cardDecisions).some((item) => item.status === 'pending')) {
        return;
    }

    const decided = Object.entries(cardDecisions).filter(([, item]) => item.status === 'decided');
    const decisions = Object.fromEntries(decided.map(([id, item]) => [id, item.decision]));

    decided.forEach(([, item]) => {
        item.status = 'submitted';
    });

    errorBanner.value = null;

    messages.value.push(newReply());
    const liveReply = messages.value[messages.value.length - 1];

    await streamInto(decideChat.url(conversationId.value), { decisions }, liveReply);
};

/** A question's answer resumes the turn as an edit; the server restores the model's own question. */
const onQuestionAnswer = (card: QuestionPayload, answer: string): void => {
    void onCardDecision(card, { action: 'edit', arguments: { answer } }, answer);
};

/**
 * The admin pressed stop. Generation runs server-side now, so hanging up
 * would leave it running (and writing): tell the server first, then let the
 * reader drain the terminal `done` the job writes on its way out. The abort
 * is the fallback for a cancel request that never lands.
 */
const stopStreaming = async (): Promise<void> => {
    const turn = currentTurn;

    if (turn === null) {
        abortController?.abort();
        return;
    }

    try {
        await fetch(cancelTurn.url(turn), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-XSRF-TOKEN': xsrfToken(), Accept: 'application/json' },
        });
    } catch {
        abortController?.abort();
    }
};

const startNewConversation = (): void => {
    if (isStreaming.value) {
        void stopStreaming();
    }

    sessionStorage.removeItem(CONVERSATION_STORAGE_KEY);
    conversationId.value = null;
    messages.value = [];
    Object.keys(cardDecisions).forEach((id) => delete cardDecisions[id]);
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

/**
 * Pick a turn left running when the page was closed back up, replaying it
 * from the start of its buffer. A turn that finished after the tab went away
 * was persisted in the meantime, so the rehydrated thread already ends with
 * its answer — the replay would duplicate it, and the settle below drops the
 * older copy in favour of the richer replayed one.
 */
const resumeActiveTurn = async (): Promise<void> => {
    const storedTurn = sessionStorage.getItem(ACTIVE_TURN_STORAGE_KEY);

    if (!storedTurn || !props.assistant.enabled) {
        return;
    }

    beginTurn();
    // Cursor 0 — a restored turn has seen nothing of this buffer yet, so the
    // replay rebuilds the bubble from its first frame.
    currentTurn = storedTurn;

    messages.value.push(newReply());
    const liveReply = messages.value[messages.value.length - 1];

    try {
        await followTurn(liveReply, true);
    } finally {
        const text = answerText(liveReply.segments);
        const previous = messages.value[messages.value.indexOf(liveReply) - 1];

        if (text !== '' && previous?.role === 'assistant' && answerText(previous.segments) === text) {
            messages.value = messages.value.filter((item) => item.id !== previous.id);
        }

        // The replay never reached the pause (an expired buffer, an exhausted
        // ladder): draw the server's pending cards after all, so an operator
        // is never left with a paused conversation and nothing to decide.
        if (deferredCards.length > 0 && !hasCard(liveReply)) {
            paintCards(liveReply, deferredCards);
        }

        deferredCards = [];

        await settleTurn(liveReply);
    }
};

onMounted(() => {
    // Restore the thread first, then pick up whatever turn was still running
    // when the page was left — in that order, so the resumed reply lands after
    // the history it continues, and after its cards were repainted.
    void rehydrateConversation().then(resumeActiveTurn);
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

                <!-- Assistant bubble: the turn in the order it happened. -->
                <div v-else class="flex justify-start">
                    <div class="assistant-bubble max-w-[85%] space-y-3 rounded-2xl rounded-ss-sm bg-muted px-4 py-2.5">
                        <div v-if="isBubbleEmpty(message)" class="flex items-center gap-1 py-1" aria-label="المساعد يكتب الآن">
                            <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 0ms" />
                            <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 150ms" />
                            <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 300ms" />
                        </div>

                        <template v-for="(group, index) in groupsOf(message)" :key="index">
                            <Markdown
                                v-if="group.type === 'text'"
                                class="text-sm leading-relaxed"
                                :source="group.text"
                                :live="isLiveGroup(message, index)"
                            />

                            <div v-else-if="group.type === 'card'">
                                <QuestionCard
                                    v-if="isQuestionCard(group.card)"
                                    :key="group.card.id"
                                    :card="group.card"
                                    :disabled="!isPending(group.card) || isStreaming"
                                    :answer="trackedFor(group.card).answer"
                                    :skipped="trackedFor(group.card).decision?.action === 'reject'"
                                    placeholder="اكتب إجابتك…"
                                    send-label="إرسال"
                                    skip-label="تخطٍّ"
                                    answered-label="إجابتك"
                                    skipped-label="تم التخطي"
                                    pending-label="بانتظار ردّك"
                                    @answer="(answer: string) => onQuestionAnswer(group.card as QuestionPayload, answer)"
                                    @skip="onCardDecision(group.card, { action: 'reject' })"
                                />

                                <template v-else>
                                    <!-- Keyed by call id: a repainted card is a NEW card, and the
                                         kit's form seeds its values once per mount. -->
                                    <ApprovalCard
                                        :key="group.card.id"
                                        :card="asApprovalCard(group.card)"
                                        :disabled="!isPending(group.card) || isStreaming"
                                        :pending="isPending(group.card)"
                                        confirm-label="تأكيد"
                                        reject-label="رفض"
                                        destructive-label="لا يمكن التراجع"
                                        undoable-label="قابل للتراجع"
                                        pending-label="بانتظار موافقتك"
                                        details-label="تفاصيل تقنية"
                                        empty-label="غير محدَّد"
                                        @decide="(decision: ClientDecision) => onCardDecision(group.card, decision)"
                                    >
                                        <template #icon>
                                            <span
                                                class="flex size-6 items-center justify-center rounded-full"
                                                :class="
                                                    asApprovalCard(group.card).destructive
                                                        ? 'bg-destructive/10 text-destructive'
                                                        : 'bg-background text-muted-foreground'
                                                "
                                            >
                                                <component :is="categoryOf(asApprovalCard(group.card)).icon" class="size-3.5" />
                                            </span>
                                        </template>
                                    </ApprovalCard>

                                    <p
                                        v-if="decisionChip(group.card)"
                                        class="mt-1.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            decisionChip(group.card)?.ok
                                                ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                : 'bg-muted text-muted-foreground'
                                        "
                                    >
                                        <component :is="decisionChip(group.card)?.icon" class="size-3" />
                                        {{ decisionChip(group.card)?.label }}
                                    </p>
                                </template>
                            </div>

                            <ProcessGroup
                                v-else
                                :items="group.items"
                                :live="isLiveGroup(message, index)"
                                :label="isLiveGroup(message, index) ? 'يفكّر' : 'خطوات التفكير'"
                            />
                        </template>
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
 * The kit components are themed through `--ai-kit-*` variables only, so the
 * panel's own tokens are mapped once here and every card, chip and disclosure
 * follows. The bubble is `bg-muted`, so surfaces inside it take the page
 * background to read as raised rather than vanishing into it, and the
 * destructive foreground is the primary one (near-white) because this design
 * system's `--destructive-foreground` is the color destructive TEXT takes,
 * not the text on a destructive fill.
 */
.assistant-bubble {
    --ai-kit-accent: var(--primary);
    --ai-kit-accent-fg: var(--primary-foreground);
    --ai-kit-destructive: var(--destructive);
    --ai-kit-destructive-fg: var(--primary-foreground);
    --ai-kit-border: var(--border);
    --ai-kit-surface: var(--background);
    --ai-kit-input-bg: var(--background);
    --ai-kit-muted: var(--muted-foreground);
    --ai-kit-muted-bg: var(--background);
    --ai-kit-radius: var(--radius-md);
    --ai-kit-chip-bg: var(--background);
    --ai-kit-chip-color: var(--muted-foreground);
    --ai-kit-chip-failed-color: var(--destructive);
}
</style>
