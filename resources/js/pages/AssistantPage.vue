<script setup lang="ts">
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import DocsLayout from '@/components/layout/DocsLayout.vue';
import PageHeader from '@/components/page/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { formatFileSize } from '@/lib/formatters';
import { cancel as cancelTurn, send as sendChat, show as showConversation, stream as streamTurn } from '@/routes/ai/chat';
import { show as attachmentFile, store as storeAttachment } from '@/routes/ai/chat/attachments';
import { show as showPage } from '@/routes/pages';
import { Link } from '@inertiajs/vue3';
import { readSseStream } from '@saad5400/ai-kit/sse';
import { createTimeline, groupSegments, type Segment, type SegmentGroup, type Timeline } from '@saad5400/ai-kit/timeline';
import Markdown from '@saad5400/ai-kit/vue/Markdown.vue';
import ProcessGroup from '@saad5400/ai-kit/vue/ProcessGroup.vue';
import { BookOpenText, CircleStop, Info, LoaderCircle, Paperclip, RotateCcw, SendHorizontal, Sparkles, X } from 'lucide-vue-next';
import { markRaw, nextTick, onBeforeUnmount, onMounted, reactive, ref, useTemplateRef } from 'vue';

/**
 * The student assistant chat. Anonymous, session-owned conversations:
 * POST /ai/chat streams the reply as ai-kit SSE frames
 * (turn/reasoning/delta/tool/citations/done/error), read with the kit's
 * `readSseStream`; pre-flight failures (feature disabled 503, budget 503,
 * rate limits 429, validation 422) arrive as plain JSON. Like
 * AiSearchPalette, the disabled state is discovered lazily from the
 * endpoints so the cached page never bakes in a stale feature flag.
 *
 * Each turn is modelled as an ORDERED segment list (ai-kit's `createTimeline`)
 * rather than one accumulated text string beside one accumulated reasoning
 * string: the events already arrive chronologically, and only a list can
 * render "thought, searched, answered, thought again" in the order it
 * happened. Every SSE event is pushed through the timeline — it ignores the
 * ones it does not own — while `citations`, `done` and `error` are handled
 * beside it. `groupSegments` then collapses thinking and tool runs into one
 * disclosure, leaving the answer text top-level.
 *
 * ── Resumable turns ── The reply is generated into a durable server-side
 * buffer, so it survives the connection that asked for it. Every frame the
 * server sends carries an `id:` — the buffer sequence number, handed to the
 * reader's third argument — and re-issuing the last one as `?cursor=` is what
 * makes a reconnect resume rather than replay. So a drop (lost signal, a
 * backgrounded tab, the server's own stream ceiling) climbs a reconnect
 * ladder against `stream`, and a page reload picks the turn back up from the
 * id parked in sessionStorage. Seeing any frame forgives the previous drop,
 * so a long turn never exhausts its retries.
 *
 * Because generation no longer rides the request, hanging up does not stop
 * it: the stop button tells the SERVER to stop, and the turn finishes early
 * with what it had.
 *
 * Thinking and tool progress are live-only — the server persists neither, so
 * a rehydrated thread shows the answer alone instead of inventing them.
 */

defineOptions({ layout: false });

interface Props {
    page?: { html_content: unknown; title?: string } | null;
    hasContent?: boolean;
    disclaimer: string;
    seo: SeoData;
}

withDefaults(defineProps<Props>(), { page: null, hasContent: false });

interface Citation {
    title: string;
    slug: string;
    heading: string | null;
}

/**
 * A file the student actually sent with a message — not a composer entry.
 * Same shape whether it was just sent (built from the upload's own result) or
 * restored from the thread endpoint, so one chip renders both.
 */
interface SentAttachment {
    id: string;
    name: string;
    mime: string | null;
    size: number | null;
    /** The authorized route that streams the bytes back; never a public URL. */
    url: string;
}

interface ChatMessage {
    id: number;
    role: 'user' | 'assistant';
    /** The student's own text; an assistant turn renders from `segments` instead. */
    content: string;
    citations: Citation[];
    /** The files sent with this message; absent on a message that carried none. */
    attachments?: SentAttachment[];
    /** The turn in arrival order — mutated in place by `timeline`. */
    segments: Segment[];
    timeline: Timeline;
    streaming?: boolean;
    failed?: boolean;
}

/**
 * Tool names are English identifiers the model and the logs speak; a student
 * gets told what the assistant is doing instead. An unmapped tool falls back
 * to its raw name rather than disappearing.
 */
const TOOL_LABELS: Record<string, string> = {
    search_content: 'يبحث في صفحات الدليل',
    get_page: 'يقرأ صفحة من الدليل',
    get_document: 'يقرأ مستنداً مرفقاً',
    find_tutors: 'يبحث عن مدرّسين',
    calculate_gpa: 'يحسب المعدل التراكمي',
    calculate_deprivation: 'يحسب نسبة الحرمان',
    calculate_transfer: 'يحسب معدل التحويل',
    date_time: 'يتحقق من التاريخ',
    truth_table: 'يبني جدول الصدق',
    list_stale_pages: 'يفحص الصفحات القديمة',
};

const toolLabel = (name: string): string => TOOL_LABELS[name] ?? name;

/** A streaming bubble with nothing in it yet still shows the typing dots. */
const isBubbleEmpty = (message: ChatMessage): boolean => message.streaming === true && message.segments.length === 0;

const groupsOf = (message: ChatMessage): SegmentGroup[] => groupSegments(message.segments);

/** The group the model is still writing into: the last one, while it streams. */
const isLiveGroup = (message: ChatMessage, index: number): boolean => message.streaming === true && index === groupsOf(message).length - 1;

const hasText = (message: ChatMessage): boolean => message.segments.some((segment) => segment.type === 'text' && segment.text !== '');

type AttachmentStatus = 'uploading' | 'ready' | 'failed';

interface PendingAttachment {
    clientId: number;
    attachmentId: string | null;
    name: string;
    mime: string;
    size: number;
    progress: number;
    status: AttachmentStatus;
    error?: string;
}

const CONVERSATION_STORAGE_KEY = 'assistant-conversation-id';
/** The turn still in flight when the page was left, so a reload can resume it. */
const ACTIVE_TURN_STORAGE_KEY = 'assistant-active-turn-id';
/** Reconnect attempts before a turn is given up on — the ladder tops out at 8s a try. */
const MAX_RECONNECTS = 8;
const MAX_MESSAGE_LENGTH = 2000;
const MAX_ATTACHMENTS = 5;
const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;
const ALLOWED_ATTACHMENT_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

const messages = ref<ChatMessage[]>([]);
const draft = ref('');
const isStreaming = ref(false);
const isRehydrating = ref(false);
const assistantDisabled = ref(false);
const disabledMessage = ref('المساعد الذكي غير متاح حالياً.');
const dailyQuotaHit = ref(false);
const errorBanner = ref<string | null>(null);
const attachments = ref<PendingAttachment[]>([]);
const conversationId = ref<string | null>(null);

const messagesContainer = useTemplateRef<HTMLDivElement>('messagesContainer');
const fileInput = useTemplateRef<HTMLInputElement>('fileInput');
const draftInput = useTemplateRef<InstanceType<typeof Textarea>>('draftInput');

/** Touch/coarse-pointer detection drives Enter behavior, autofocus, and the keyboard hint. */
const isCoarsePointer = ref(false);

const examplePrompts = [
    'كيف أحسب معدلي التراكمي؟',
    'ما شروط الحرمان من دخول الاختبار النهائي؟',
    'ما مسارات التخصص المتاحة في الكلية؟',
    'احسب معدلي من صورة سجلي الأكاديمي المرفقة',
];

let nextLocalId = 1;
let nextClientId = 1;
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

/** Whether the visitor has walked away from the turn currently being read. */
const readAborted = (): boolean => abortController?.signal.aborted === true;

/** The answer as plain text, for matching a replayed turn against a rehydrated one. */
const answerText = (segments: Segment[]): string =>
    segments
        .filter((segment) => segment.type === 'text')
        .map((segment) => segment.text)
        .join('');

/**
 * A message whose segment list is reactive and handed to the timeline, so the
 * reducer's in-place mutations re-render the bubble.
 */
const newMessage = (role: 'user' | 'assistant', content = ''): ChatMessage => {
    const segments = reactive<Segment[]>([]);

    return { id: nextLocalId++, role, content, citations: [], segments, timeline: markRaw(createTimeline(segments)) };
};

const xsrfToken = (): string => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

const scrollToBottom = async (): Promise<void> => {
    await nextTick();
    messagesContainer.value?.scrollTo({ top: messagesContainer.value.scrollHeight });
};

const markDisabled = (message?: string): void => {
    assistantDisabled.value = true;
    if (message) {
        disabledMessage.value = message;
    }
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

    if (!storedId) {
        return;
    }

    isRehydrating.value = true;

    try {
        const response = await fetch(showConversation.url(storedId), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (response.status === 503) {
            markDisabled((await readJsonMessage(response)) ?? undefined);
            return;
        }

        if (!response.ok) {
            sessionStorage.removeItem(CONVERSATION_STORAGE_KEY);
            return;
        }

        const payload = (await response.json()) as {
            messages: { role: string; content: string; citations: Citation[]; attachments?: SentAttachment[] }[];
        };

        conversationId.value = storedId;

        // Persisted text replays through the timeline as one `delta`, so a
        // restored turn is the same segment model as a live one.
        messages.value = payload.messages
            .filter((message) => message.role === 'user' || message.role === 'assistant')
            .map((message) => {
                const restored = newMessage(message.role as 'user' | 'assistant', message.content);
                restored.citations = message.citations ?? [];

                if (message.attachments !== undefined && message.attachments.length > 0) {
                    restored.attachments = message.attachments;
                }

                if (restored.role === 'assistant') {
                    restored.timeline.push('delta', { text: message.content });
                }

                return restored;
            });

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
    // not own — the app's own events below are handled beside it, not instead.
    // A tool's name is swapped for its Arabic label on the way in: the chip
    // renders whatever the segment carries, and a student reads none of the
    // identifiers the model and the logs speak.
    reply.timeline.push(event, event === 'tool' && typeof data.name === 'string' ? { ...data, name: toolLabel(data.name) } : data);

    if (event === 'citations' && Array.isArray(data.items)) {
        reply.citations = data.items as Citation[];
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
 * Only an abort — the visitor leaving the page — returns without reconnecting,
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
                reply.failed = !hasText(reply);
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
                // The buffer expired, or never belonged to this session: there
                // is no turn to resume, so stop rather than climb the ladder.
                turnFinished = true;
                reply.failed = !hasText(reply);
                errorBanner.value = 'انتهت صلاحية هذا الرد. أعد إرسال سؤالك.';

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
 * that stopped because the visitor navigated away leaves it in place on
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

    if (reply.failed && !hasText(reply)) {
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
 * Pick a turn left running when the page was closed back up, replaying it
 * from the start of its buffer. A turn that finished after the tab went away
 * was persisted in the meantime, so the rehydrated thread already ends with
 * its answer — the replay would duplicate it, and the settle below drops the
 * older copy in favour of the richer replayed one.
 */
const resumeActiveTurn = async (): Promise<void> => {
    const storedTurn = sessionStorage.getItem(ACTIVE_TURN_STORAGE_KEY);

    if (!storedTurn || assistantDisabled.value) {
        return;
    }

    beginTurn();
    // Cursor 0 — a restored turn has seen nothing of this buffer yet, so the
    // replay rebuilds the bubble from its first frame.
    currentTurn = storedTurn;

    const reply = newMessage('assistant');
    reply.streaming = true;
    messages.value.push(reply);
    const liveReply = messages.value[messages.value.length - 1];

    try {
        await followTurn(liveReply, true);
    } finally {
        const text = answerText(liveReply.segments);
        const previous = messages.value[messages.value.indexOf(liveReply) - 1];

        if (text !== '' && previous?.role === 'assistant' && answerText(previous.segments) === text) {
            messages.value = messages.value.filter((item) => item.id !== previous.id);
        }

        await settleTurn(liveReply);
    }
};

const sendMessage = async (): Promise<void> => {
    const message = draft.value.trim();

    if (message === '' || message.length > MAX_MESSAGE_LENGTH || isStreaming.value || assistantDisabled.value || dailyQuotaHit.value) {
        return;
    }

    if (attachments.value.some((attachment) => attachment.status === 'uploading')) {
        errorBanner.value = 'انتظر اكتمال رفع المرفقات أولاً.';
        return;
    }

    errorBanner.value = null;

    const sent = attachments.value.filter((attachment) => attachment.status === 'ready' && attachment.attachmentId);
    const attachmentIds = sent.map((attachment) => attachment.attachmentId as string);

    const question = newMessage('user', message);

    // The composer is cleared below, so the bubble takes its own copy of what
    // was sent — otherwise the files vanish the moment they are sent, which is
    // the defect this closes. The URL comes from the same named route the
    // rehydrated payload carries, so a live chip and a restored one open the
    // identical door.
    if (sent.length > 0) {
        question.attachments = sent.map((attachment) => ({
            id: attachment.attachmentId as string,
            name: attachment.name,
            mime: attachment.mime === '' ? null : attachment.mime,
            size: attachment.size,
            url: attachmentFile.url(attachment.attachmentId as string),
        }));
    }

    messages.value.push(question);

    const reply = newMessage('assistant');
    reply.streaming = true;
    messages.value.push(reply);
    const liveReply = messages.value[messages.value.length - 1];

    draft.value = '';
    attachments.value = [];
    beginTurn();
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
                ...(attachmentIds.length > 0 ? { attachment_ids: attachmentIds } : {}),
            }),
            signal: abortController?.signal,
        });

        const contentType = response.headers.get('Content-Type') ?? '';

        if (!contentType.includes('text/event-stream')) {
            const serverMessage = await readJsonMessage(response);

            liveReply.failed = true;

            // A pre-flight refusal never opened a turn, so there is nothing to
            // resume or clean up beyond the bubble itself.
            turnFinished = true;

            if (response.status === 503) {
                markDisabled(serverMessage ?? undefined);
            } else if (response.status === 429) {
                errorBanner.value = serverMessage ?? 'محاولات كثيرة خلال وقت قصير، انتظر دقيقة ثم أعد المحاولة.';
                if (serverMessage?.includes('اليومي')) {
                    dailyQuotaHit.value = true;
                }
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
            liveReply.failed = !hasText(liveReply);
            errorBanner.value = 'تعذر الاتصال بالخادم. تأكد من اتصالك ثم أعد المحاولة.';
        }
    } finally {
        // The POST's own stream ended. Unless the turn reached a terminal
        // event, it is still generating server-side — reconnect and follow it
        // rather than abandoning a reply that is still being written.
        await followTurn(liveReply);
        await settleTurn(liveReply);
    }
};

/**
 * The visitor pressed stop. Generation runs server-side now, so hanging up
 * would leave it running (and billing): tell the server first, then let the
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
    errorBanner.value = null;
};

const focusComposer = (): void => {
    (draftInput.value?.$el as HTMLTextAreaElement | undefined)?.focus();
};

const useExamplePrompt = (prompt: string): void => {
    draft.value = prompt;
    focusComposer();
};

const openFilePicker = (): void => {
    if (attachments.value.length >= MAX_ATTACHMENTS) {
        errorBanner.value = `يمكن إرفاق ${MAX_ATTACHMENTS} ملفات كحد أقصى في الرسالة الواحدة.`;
        return;
    }

    fileInput.value?.click();
};

const uploadAttachment = (file: File, pending: PendingAttachment): void => {
    const body = new FormData();
    body.append('file', file);

    const request = new XMLHttpRequest();
    request.open('POST', storeAttachment.url());
    request.setRequestHeader('X-XSRF-TOKEN', xsrfToken());
    request.setRequestHeader('Accept', 'application/json');
    request.responseType = 'json';

    request.upload.onprogress = (event) => {
        if (event.lengthComputable) {
            pending.progress = Math.round((event.loaded / event.total) * 100);
        }
    };

    request.onload = () => {
        if (request.status === 201) {
            const payload = request.response as { attachment_id: string };
            pending.attachmentId = payload.attachment_id;
            pending.status = 'ready';
            pending.progress = 100;
            return;
        }

        pending.status = 'failed';

        const payload = request.response as { message?: string; errors?: Record<string, string[]> } | null;
        pending.error = Object.values(payload?.errors ?? {})[0]?.[0] ?? payload?.message ?? 'تعذر رفع الملف.';

        if (request.status === 503) {
            markDisabled(payload?.message ?? undefined);
        }
    };

    request.onerror = () => {
        pending.status = 'failed';
        pending.error = 'تعذر رفع الملف. تأكد من اتصالك.';
    };

    request.send(body);
};

const onFilesSelected = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';

    for (const file of files) {
        if (attachments.value.length >= MAX_ATTACHMENTS) {
            errorBanner.value = `يمكن إرفاق ${MAX_ATTACHMENTS} ملفات كحد أقصى في الرسالة الواحدة.`;
            break;
        }

        const pending: PendingAttachment = {
            clientId: nextClientId++,
            attachmentId: null,
            name: file.name,
            mime: file.type,
            size: file.size,
            progress: 0,
            status: 'uploading',
        };

        if (!ALLOWED_ATTACHMENT_TYPES.includes(file.type)) {
            pending.status = 'failed';
            pending.error = 'نوع الملف غير مدعوم — يُقبل PDF أو صورة (JPEG، PNG، WebP).';
        } else if (file.size > MAX_ATTACHMENT_BYTES) {
            pending.status = 'failed';
            pending.error = 'يجب ألا يتجاوز حجم الملف ١٠ ميجابايت.';
        }

        attachments.value.push(pending);

        if (pending.status === 'uploading') {
            uploadAttachment(file, attachments.value[attachments.value.length - 1]);
        }
    }
};

const removeAttachment = (clientId: number): void => {
    attachments.value = attachments.value.filter((attachment) => attachment.clientId !== clientId);
};

/** Desktop: Enter sends, Shift+Enter breaks the line. Touch keyboards keep Enter as a newline — the send button sends. */
const onComposerKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Enter' && !event.shiftKey && !isCoarsePointer.value) {
        event.preventDefault();
        void sendMessage();
    }
};

const citationUrl = (citation: Citation): string => showPage.url({ slug: citation.slug.replace(/^\/+/, '') }) || '/';

onMounted(() => {
    isCoarsePointer.value = window.matchMedia('(pointer: coarse)').matches;

    // Restore the thread first, then pick up whatever turn was still running
    // when the page was left — in that order, so the resumed reply lands after
    // the history it continues.
    void rehydrateConversation().then(resumeActiveTurn);

    if (!isCoarsePointer.value) {
        focusComposer();
    }
});

onBeforeUnmount(() => abortController?.abort());
</script>

<template>
    <SeoHead :seo="seo" />
    <DocsLayout>
        <PageHeader title="المساعد الذكي" icon="solar:chat-round-line-broken" />

        <div v-if="hasContent && page" class="typography mb-6">
            <RichContentRenderer :content="page.html_content" />
        </div>

        <div class="flex h-[calc(100dvh-11.375rem)] min-h-96 flex-col">
            <!-- Conversation header -->
            <div class="flex items-center justify-between gap-2 border-b border-border/60 pb-2">
                <div class="flex items-center gap-2 text-xs text-muted-foreground">
                    <Sparkles class="size-3.5 shrink-0 text-amber-500" />
                    إجاباته مبنية على محتوى الدليل — وقد يخطئ، فتحقق من المصادر.
                </div>
                <Button
                    v-if="messages.length > 0"
                    variant="ghost"
                    size="sm"
                    class="shrink-0 gap-1.5 text-muted-foreground"
                    @click="startNewConversation"
                >
                    <RotateCcw class="size-3.5" />
                    محادثة جديدة
                </Button>
            </div>

            <!-- Messages -->
            <div ref="messagesContainer" class="min-h-0 flex-1 space-y-4 overflow-y-auto py-4" aria-live="polite">
                <div v-if="isRehydrating" class="flex flex-col gap-3" aria-hidden="true">
                    <div class="h-10 w-2/3 animate-pulse self-start rounded-2xl bg-muted" />
                    <div class="h-16 w-3/4 animate-pulse self-end rounded-2xl bg-muted/70" />
                </div>

                <div
                    v-else-if="messages.length === 0 && !assistantDisabled"
                    class="flex h-full flex-col items-center justify-center gap-3 px-2 py-8 text-center"
                >
                    <Sparkles class="size-8 text-amber-500" />
                    <p class="font-medium">اسأل عن أي شيء يخص كلية الحاسبات</p>
                    <p class="max-w-md text-sm text-muted-foreground">
                        اللوائح، الحرمان، التخصصات، حساب المعدل… ويمكنك إرفاق صورة سجلك الأكاديمي وطلب حساب معدلك.
                    </p>

                    <div class="mt-3 grid w-full max-w-xl gap-2 sm:grid-cols-2">
                        <button
                            v-for="prompt in examplePrompts"
                            :key="prompt"
                            type="button"
                            class="min-h-11 rounded-lg border border-border/70 bg-muted/40 px-3 py-2 text-start text-sm text-foreground/80 transition hover:border-border hover:bg-muted hover:text-foreground"
                            @click="useExamplePrompt(prompt)"
                        >
                            {{ prompt }}
                        </button>
                    </div>
                </div>

                <template v-for="message in messages" :key="message.id">
                    <!-- User bubble -->
                    <div v-if="message.role === 'user'" class="flex flex-col items-end gap-1.5">
                        <div class="max-w-[85%] rounded-2xl rounded-se-sm bg-primary px-4 py-2.5 text-sm whitespace-pre-wrap text-primary-foreground">
                            {{ message.content }}
                        </div>

                        <!-- The files sent with this message, openable only by
                             the session that sent them. `dir="auto"` per name so
                             a Latin filename inside an Arabic thread stays
                             readable, and the size is LTR-isolated so it cannot
                             reorder against the surrounding RTL text. -->
                        <ul v-if="message.attachments?.length" class="flex max-w-[85%] flex-wrap justify-end gap-1.5">
                            <li v-for="attachment in message.attachments" :key="attachment.id">
                                <a
                                    :href="attachment.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted px-2.5 py-1 text-xs text-muted-foreground transition hover:bg-foreground/10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    :aria-label="`فتح المرفق ${attachment.name}`"
                                >
                                    <Paperclip class="size-3 shrink-0" />
                                    <bdi dir="auto" class="max-w-40 truncate">{{ attachment.name }}</bdi>
                                    <span v-if="attachment.size" dir="ltr" class="shrink-0 text-[10px] opacity-70">{{
                                        formatFileSize(attachment.size)
                                    }}</span>
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- Assistant bubble -->
                    <div v-else class="flex justify-start">
                        <div class="assistant-bubble max-w-[85%] rounded-2xl rounded-ss-sm bg-muted px-4 py-2.5">
                            <div v-if="isBubbleEmpty(message)" class="flex items-center gap-1 py-1" aria-label="المساعد يكتب الآن">
                                <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 0ms" />
                                <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 150ms" />
                                <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 300ms" />
                            </div>

                            <!-- The turn in the order it happened: thinking and searches
                                 behind one disclosure, the answer top-level. -->
                            <template v-for="(group, index) in groupsOf(message)" :key="index">
                                <Markdown
                                    v-if="group.type === 'text'"
                                    class="text-sm leading-relaxed"
                                    :source="group.text"
                                    :live="isLiveGroup(message, index)"
                                />

                                <ProcessGroup
                                    v-else-if="group.type === 'process'"
                                    class="my-2"
                                    :items="group.items"
                                    :live="isLiveGroup(message, index)"
                                    :label="isLiveGroup(message, index) ? 'يفكّر' : 'خطوات التفكير'"
                                />
                            </template>

                            <div v-if="message.citations.length > 0" class="mt-3 flex flex-wrap gap-1.5 border-t border-border/60 pt-2">
                                <Link
                                    v-for="(citation, index) in message.citations"
                                    :key="`${citation.slug}-${index}`"
                                    :href="citationUrl(citation)"
                                    class="inline-flex max-w-full items-center gap-1 rounded-full border border-border bg-card px-2.5 py-1 text-xs text-muted-foreground transition hover:border-foreground/25 hover:text-foreground"
                                >
                                    <BookOpenText class="size-3 shrink-0" />
                                    <span class="truncate">{{ citation.title }}{{ citation.heading ? ` — ${citation.heading}` : '' }}</span>
                                </Link>
                            </div>

                            <div v-if="hasText(message)" class="mt-2 flex items-center gap-1 text-[11px] text-muted-foreground/70">
                                <Info class="size-3 shrink-0" />
                                <span>{{ disclaimer }}</span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Disabled state -->
            <div v-if="assistantDisabled" class="border-t border-border/60 py-8 text-center text-sm text-muted-foreground">
                {{ disabledMessage }}
            </div>

            <!-- Daily quota state -->
            <div v-else-if="dailyQuotaHit" class="border-t border-border/60 py-8 text-center text-sm text-muted-foreground">
                وصلت إلى الحد اليومي لرسائل المساعد لهذه الجلسة. عد غداً وسيسعدنا مساعدتك.
            </div>

            <!-- Composer -->
            <div v-else class="border-t border-border/60 pt-3">
                <div v-if="errorBanner" class="mb-2 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                    {{ errorBanner }}
                </div>

                <div v-if="attachments.length > 0" class="mb-2 flex flex-wrap gap-1.5">
                    <span
                        v-for="attachment in attachments"
                        :key="attachment.clientId"
                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                        :class="
                            attachment.status === 'failed'
                                ? 'border-destructive/40 bg-destructive/10 text-destructive'
                                : 'border-border bg-muted text-muted-foreground'
                        "
                    >
                        <LoaderCircle v-if="attachment.status === 'uploading'" class="size-3 animate-spin" />
                        <Paperclip v-else class="size-3" />
                        <span class="max-w-40 truncate">{{ attachment.name }}</span>
                        <span v-if="attachment.status === 'uploading'">{{ attachment.progress }}٪</span>
                        <span v-else-if="attachment.status === 'ready'" class="text-[10px]">سيُحلَّل مع رسالتك</span>
                        <span v-else class="text-[10px]">{{ attachment.error }}</span>
                        <button
                            type="button"
                            class="rounded-full p-0.5 transition hover:bg-foreground/10"
                            :aria-label="`إزالة المرفق ${attachment.name}`"
                            @click="removeAttachment(attachment.clientId)"
                        >
                            <X class="size-3" />
                        </button>
                    </span>
                </div>

                <div class="flex items-end gap-2">
                    <input
                        ref="fileInput"
                        type="file"
                        class="hidden"
                        multiple
                        accept="application/pdf,image/jpeg,image/png,image/webp"
                        @change="onFilesSelected"
                    />
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-11 shrink-0 text-muted-foreground hover:text-foreground md:size-9"
                                aria-label="إرفاق ملف"
                                :disabled="isStreaming"
                                @click="openFilePicker"
                            >
                                <Paperclip class="size-4" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>إرفاق PDF أو صورة — حتى ٥ ملفات، ١٠ ميجابايت للملف</TooltipContent>
                    </Tooltip>

                    <Textarea
                        ref="draftInput"
                        v-model="draft"
                        dir="rtl"
                        rows="1"
                        :maxlength="MAX_MESSAGE_LENGTH"
                        placeholder="اكتب سؤالك هنا…"
                        aria-label="نص الرسالة"
                        class="max-h-40 min-h-11 flex-1 resize-none md:min-h-9"
                        :disabled="isStreaming"
                        @keydown="onComposerKeydown"
                    />

                    <Button
                        v-if="isStreaming"
                        variant="outline"
                        size="icon"
                        class="size-11 shrink-0 md:size-9"
                        aria-label="إيقاف التوليد"
                        @click="stopStreaming"
                    >
                        <CircleStop class="size-4 text-destructive" />
                    </Button>
                    <Button
                        v-else
                        size="icon"
                        class="size-11 shrink-0 md:size-9"
                        aria-label="إرسال الرسالة"
                        :disabled="draft.trim() === ''"
                        :title="draft.trim() === '' ? 'اكتب رسالة أولاً' : undefined"
                        @click="sendMessage"
                    >
                        <SendHorizontal class="size-4 -scale-x-100" />
                    </Button>
                </div>

                <div
                    v-if="!isCoarsePointer || draft.length >= MAX_MESSAGE_LENGTH - 200"
                    class="mt-1.5 flex min-h-4 items-center justify-between gap-2 text-xs text-muted-foreground"
                >
                    <p v-if="!isCoarsePointer">Enter للإرسال — Shift+Enter لسطر جديد.</p>
                    <p v-if="draft.length >= MAX_MESSAGE_LENGTH - 200" class="ms-auto" dir="ltr">
                        <span class="tabular-nums">{{ draft.length }} / {{ MAX_MESSAGE_LENGTH }}</span>
                    </p>
                </div>
            </div>
        </div>
    </DocsLayout>
</template>

<style scoped>
/*
 * The kit components are themed through `--ai-kit-*` variables only, so the
 * site's own tokens are mapped once here and the markdown, the disclosure and
 * its chips all follow. The bubble is `bg-muted`, so surfaces inside it take
 * the page background to read as raised rather than vanishing into it, and
 * the tool chips carry Arabic labels rather than identifiers — so they drop
 * the monospace face.
 */
.assistant-bubble {
    --ai-kit-accent: var(--primary);
    --ai-kit-accent-fg: var(--primary-foreground);
    --ai-kit-destructive: var(--destructive);
    --ai-kit-border: var(--border);
    --ai-kit-surface: var(--background);
    --ai-kit-muted: var(--muted-foreground);
    --ai-kit-muted-bg: var(--background);
    --ai-kit-radius: var(--radius-md);
    --ai-kit-chip-bg: var(--background);
    --ai-kit-chip-color: var(--muted-foreground);
    --ai-kit-chip-failed-color: var(--destructive);
    --ai-kit-chip-font: inherit;
}
</style>
