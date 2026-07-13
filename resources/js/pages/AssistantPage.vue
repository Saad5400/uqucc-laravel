<script setup lang="ts">
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import DocsLayout from '@/components/layout/DocsLayout.vue';
import PageHeader from '@/components/page/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { renderMarkdown } from '@/lib/markdown';
import { send as sendChat, show as showConversation } from '@/routes/ai/chat';
import { store as storeAttachment } from '@/routes/ai/chat/attachments';
import { show as showPage } from '@/routes/pages';
import { Link } from '@inertiajs/vue3';
import { BookOpenText, CircleStop, LoaderCircle, Paperclip, RotateCcw, SendHorizontal, Sparkles, X } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';

/**
 * The student assistant chat. Anonymous, session-owned conversations:
 * POST /ai/chat streams the reply as SSE frames (delta/citations/done/error)
 * which are parsed off a fetch ReadableStream; pre-flight failures (feature
 * disabled 503, budget 503, rate limits 429, validation 422) arrive as plain
 * JSON. Like AiSearchPalette, the disabled state is discovered lazily from
 * the endpoints so the cached page never bakes in a stale feature flag.
 */

defineOptions({ layout: false });

interface Props {
    page?: { html_content: unknown; title?: string } | null;
    hasContent?: boolean;
    seo: SeoData;
}

withDefaults(defineProps<Props>(), { page: null, hasContent: false });

interface Citation {
    title: string;
    slug: string;
    heading: string | null;
}

interface ChatMessage {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    citations: Citation[];
    streaming?: boolean;
    failed?: boolean;
}

type AttachmentStatus = 'uploading' | 'ready' | 'failed';

interface PendingAttachment {
    clientId: number;
    attachmentId: string | null;
    name: string;
    progress: number;
    status: AttachmentStatus;
    error?: string;
}

const CONVERSATION_STORAGE_KEY = 'assistant-conversation-id';
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
            messages: { role: string; content: string; citations: Citation[] }[];
        };

        conversationId.value = storedId;
        messages.value = payload.messages
            .filter((message) => message.role === 'user' || message.role === 'assistant')
            .map((message) => ({
                id: nextLocalId++,
                role: message.role as 'user' | 'assistant',
                content: message.content,
                citations: message.citations ?? [],
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
    } else if (event === 'citations' && Array.isArray(data.items)) {
        reply.citations = data.items as Citation[];
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

    if (message === '' || message.length > MAX_MESSAGE_LENGTH || isStreaming.value || assistantDisabled.value || dailyQuotaHit.value) {
        return;
    }

    if (attachments.value.some((attachment) => attachment.status === 'uploading')) {
        errorBanner.value = 'انتظر اكتمال رفع المرفقات أولاً.';
        return;
    }

    errorBanner.value = null;

    const attachmentIds = attachments.value
        .filter((attachment) => attachment.status === 'ready' && attachment.attachmentId)
        .map((attachment) => attachment.attachmentId as string);

    messages.value.push({ id: nextLocalId++, role: 'user', content: message, citations: [] });

    const reply: ChatMessage = { id: nextLocalId++, role: 'assistant', content: '', citations: [], streaming: true };
    messages.value.push(reply);
    const liveReply = messages.value[messages.value.length - 1];

    draft.value = '';
    attachments.value = [];
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
                ...(attachmentIds.length > 0 ? { attachment_ids: attachmentIds } : {}),
            }),
            signal: abortController.signal,
        });

        const contentType = response.headers.get('Content-Type') ?? '';

        if (!contentType.includes('text/event-stream')) {
            const serverMessage = await readJsonMessage(response);

            liveReply.failed = true;

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

        if (liveReply.failed && liveReply.content === '') {
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
    void rehydrateConversation();

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
                    <div v-if="message.role === 'user'" class="flex justify-end">
                        <div class="max-w-[85%] rounded-2xl rounded-se-sm bg-primary px-4 py-2.5 text-sm whitespace-pre-wrap text-primary-foreground">
                            {{ message.content }}
                        </div>
                    </div>

                    <!-- Assistant bubble -->
                    <div v-else class="flex justify-start">
                        <div class="max-w-[85%] rounded-2xl rounded-ss-sm bg-muted px-4 py-2.5">
                            <div
                                v-if="message.streaming && message.content === ''"
                                class="flex items-center gap-1 py-1"
                                aria-label="المساعد يكتب الآن"
                            >
                                <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 0ms" />
                                <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 150ms" />
                                <span class="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" style="animation-delay: 300ms" />
                            </div>

                            <!-- eslint-disable-next-line vue/no-v-html -- renderMarkdown escapes + DOMPurify-sanitizes model output -->
                            <div v-else class="assistant-markdown text-sm leading-relaxed" v-html="renderMarkdown(message.content)" />

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
    background: color-mix(in oklab, var(--foreground) 8%, transparent);
    padding: 0.125rem 0.375rem;
    font-size: 0.8125em;
    font-variant-numeric: tabular-nums;
    direction: ltr;
    unicode-bidi: embed;
}

.assistant-markdown :deep(pre) {
    margin-block: 0.5rem;
    overflow-x: auto;
    border-radius: 0.5rem;
    background: color-mix(in oklab, var(--foreground) 6%, transparent);
    padding: 0.75rem;
    direction: ltr;
    text-align: start;
}

.assistant-markdown :deep(pre code) {
    padding: 0;
    background: transparent;
}

.assistant-markdown :deep(a) {
    color: var(--primary);
    text-decoration: underline;
    text-underline-offset: 2px;
    overflow-wrap: anywhere;
}

/* Bare URLs render as LTR islands so a long link never overflows the bubble or reverses in RTL prose. */
.assistant-markdown :deep(a[dir='ltr']) {
    unicode-bidi: isolate;
    font-variant-numeric: tabular-nums;
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
    background: color-mix(in oklab, var(--foreground) 5%, transparent);
    font-weight: 600;
}
</style>
