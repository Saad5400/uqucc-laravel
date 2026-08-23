<?php

namespace App\Http\Controllers\Ai;

use App\Ai\Chat\AnswerLinkGuard;
use App\Ai\Chat\AttachmentContext;
use App\Ai\Chat\CategoryContext;
use App\Ai\Chat\CitationExtractor;
use App\Ai\Chat\SessionOwner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ChatMessageRequest;
use App\Jobs\Ai\GenerateChatReply;
use App\Models\Ai\ChatAttachment;
use App\Settings\AiSettings;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Models\ConversationMessage;
use Saad\AiKit\Conversations\ConversationContent;
use Saad\AiKit\Conversations\ConversationOwnership;
use Saad\AiKit\Safety\Exceptions\AiKilledException;
use Saad\AiKit\Safety\Exceptions\AiUnavailableException;
use Saad\AiKit\Safety\KillSwitch;
use Saad\AiKit\Safety\TurnGuard;
use Saad\AiKit\Streaming\SseStream;
use Saad\AiKit\Streaming\TurnBuffer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The anonymous student-assistant chat API. Visitors have no accounts, so a
 * conversation belongs to the SESSION that started it (SessionOwner wraps the
 * session id as the laravel/ai conversation participant).
 *
 * ── Resumable turns ── The reply no longer generates inside the request.
 * POST /ai/chat opens a durable {@see TurnBuffer} turn, queues
 * {@see GenerateChatReply} to fold the model stream into it, and then TAILS
 * that buffer as Server-Sent Events. What the client reads is unchanged —
 * `turn`, then `reasoning` / `delta` / `tool` / `citations` and one terminal
 * `done` or `error` — but every frame now carries an `id:` line (its buffer
 * sequence number), and the reply keeps generating whether or not anyone is
 * listening. So a visitor who loses signal, backgrounds the tab or reloads
 * reconnects to {@see stream()} with `?cursor=<last seq>` and picks the SAME
 * turn up where it stopped, instead of losing a half-written answer.
 *
 * The leading `turn {id}` frame is what makes that possible: it is the handle
 * the client stores and re-issues. It replays on a resume too (it is buffer
 * seq 1), which the client treats as the no-op it is.
 *
 * Because generation is decoupled from the socket, hanging up no longer stops
 * it — so the visitor's stop button flags the turn through {@see cancel()},
 * and the job finishes early with whatever it had.
 *
 * ai-kit v0.5.0's `reasoning` and `tool` events are on by default here too,
 * deliberately: the page renders them as a collapsible thinking block and as
 * "يبحث في صفحات الدليل" chips, which is the progress feedback students were
 * missing. Both are safe for an anonymous surface — the kit keeps tool
 * arguments and results off the wire, so a `tool` event names the call and
 * nothing it retrieved. Neither event is persisted.
 *
 * Layered gates, in order: ai-kit's TurnGuard (503) — the assistant toggle
 * via the operator's AiSettings, the kit's cache kill switch, and the daily
 * spend budget in one call — → the `ai-chat` burst limiter on the route
 * (429) → the operator's per-session daily message quota (429). The queued
 * job re-checks the kill switch on its way out of the queue, which is where
 * the money is actually spent.
 */
class ChatController extends Controller
{
    /** Usage feature label for assistant chat turns (ai-kit usage module). */
    private const FEATURE = GenerateChatReply::FEATURE;

    /**
     * POST /ai/chat (name: ai.chat.send) — open one assistant turn and stream
     * it. Pre-flight failures (feature toggle, budget, daily quota) answer as
     * plain JSON before any turn is opened, so a refused visitor is never
     * charged a turn id.
     */
    public function send(
        ChatMessageRequest $request,
        AiSettings $settings,
        TurnGuard $guard,
        ConversationOwnership $ownership,
        TurnBuffer $buffer,
        AttachmentContext $attachmentContext,
        CategoryContext $categoryContext,
    ): JsonResponse|StreamedResponse {
        try {
            $guard->check(self::FEATURE);
        } catch (AiKilledException) {
            return $this->disabledResponse();
        } catch (AiUnavailableException $exception) {
            return response()->json(['message' => $exception->userFacingReason()], 503);
        }

        $sessionId = $request->session()->getId();

        if ($quotaResponse = $this->consumeDailyQuota($sessionId, $settings)) {
            return $quotaResponse;
        }

        $conversationId = $this->ownedConversationId($request->input('conversation_id'), $sessionId, $ownership);
        $attachments = $this->ownedAttachments($request->validated('attachment_ids', []), $sessionId);

        $question = (string) $request->validated('message');

        $prompt = $categoryContext->wrap($attachmentContext->wrap($question, $attachments), $question);

        $turnId = (string) Str::uuid7();

        // `session_id` is what {@see stream()} and {@see cancel()} read back to
        // refuse a foreign turn; the conversation id is recorded for whatever
        // reads the turn back, while the one the reply actually resolves rides
        // the terminal `done`.
        $buffer->start($turnId, [
            'session_id' => $sessionId,
            'conversation_id' => $conversationId,
        ]);

        // The handle the client stores so it can resume this turn. Appended
        // BEFORE the job is dispatched, so it is seq 1 and no worker can be
        // appending concurrently — the buffer's single-writer rule holds.
        $buffer->append($turnId, 'turn', ['id' => $turnId]);

        GenerateChatReply::dispatch(
            turnId: $turnId,
            sessionId: $sessionId,
            prompt: $prompt,
            conversationId: $conversationId,
            attachmentIds: array_map('strval', $attachments->modelKeys()),
        );

        return $this->tail($buffer, $turnId);
    }

    /**
     * GET /ai/chat/turns/{turn}/stream (name: ai.chat.stream) — resume a turn
     * from `?cursor=<last seq>` (or `Last-Event-ID`). Replaying costs nothing
     * and spends nothing, so only ownership gates it; an unknown or expired
     * turn is a 404 so the client stops retrying rather than looping.
     */
    public function stream(Request $request, TurnBuffer $buffer, string $turn): StreamedResponse
    {
        $this->ownedTurn($buffer, $turn, $request->session()->getId());

        $after = max(0, (int) $request->query('cursor', $request->header('Last-Event-ID', '0')));

        return $this->tail($buffer, $turn, $after);
    }

    /**
     * POST /ai/chat/turns/{turn}/cancel (name: ai.chat.cancel) — the visitor
     * pressed stop. Generation runs in a queued job now, so hanging up the SSE
     * connection no longer ends it: flag the turn instead and the job finishes
     * early with whatever it had streamed.
     */
    public function cancel(Request $request, TurnBuffer $buffer, string $turn): JsonResponse
    {
        $this->ownedTurn($buffer, $turn, $request->session()->getId());

        $buffer->cancel($turn);

        return response()->json(['cancelled' => true]);
    }

    /**
     * GET /ai/chat/{conversation} (name: ai.chat.show) — the stored thread
     * for rehydrating the chat panel. 404 unless the current session owns it.
     */
    public function show(
        Request $request,
        KillSwitch $killSwitch,
        ConversationOwnership $ownership,
        CitationExtractor $citations,
        AttachmentContext $attachmentContext,
        CategoryContext $categoryContext,
        AnswerLinkGuard $linkGuard,
        string $conversation,
    ): JsonResponse {
        // Reading a stored thread costs nothing, so only the kill switch
        // (toggle or cache) gates it — never the daily budget.
        if ($killSwitch->engaged(self::FEATURE)) {
            return $this->disabledResponse();
        }

        abort_unless(
            $ownership->owns($conversation, $request->session()->getId(), SessionOwner::class),
            404,
        );

        // One query for the whole thread's attachments, grouped by the message
        // they were sent with — never one query per bubble. Only the columns the
        // chip needs: `extracted_markdown` is a longText that can run to 20k
        // characters per file, and pulling it for every attachment in a thread
        // to render a filename would be pure waste.
        $attachments = ChatAttachment::query()
            ->anchoredToConversation($conversation)
            ->select(['id', 'message_id', 'original_filename', 'mime', 'size'])
            ->get()
            ->groupBy('message_id');

        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation)
            ->orderBy('id')
            ->get()
            ->map(function (ConversationMessage $message) use (
                $attachmentContext,
                $categoryContext,
                $citations,
                $linkGuard,
                $attachments,
            ): array {
                $isUser = $message->getAttribute('role') === 'user';

                $payload = [
                    'role' => (string) $message->getAttribute('role'),
                    // Wrappers unwind outside-in: the category block wraps the
                    // attachment block wraps what the visitor actually typed.
                    // The extracted TEXT stays stripped — it is evidence the
                    // model read, never something the visitor wrote — and the
                    // files themselves come back as `attachments` below.
                    'content' => $isUser
                        ? $attachmentContext->unwrap($categoryContext->unwrap((string) ConversationContent::reveal($message->getAttribute('content'))))
                        : $linkGuard->sanitize((string) ConversationContent::reveal($message->getAttribute('content'))),
                    'citations' => $message->getAttribute('role') === 'assistant'
                        ? $citations->extractFromStored((array) $message->getAttribute('tool_results'))
                        : [],
                    'created_at' => $message->getAttribute('created_at')?->toIso8601String(),
                ];

                $sent = $attachments->get((string) $message->getAttribute('id'));

                // Omitted entirely when a message carried no files, so a
                // text-only thread is byte-identical to what it sent before.
                if ($isUser && $sent !== null && $sent->isNotEmpty()) {
                    $payload['attachments'] = $sent
                        ->map(fn (ChatAttachment $attachment): array => $this->attachmentPayload($attachment))
                        ->values()
                        ->all();
                }

                return $payload;
            })
            ->values();

        return response()->json(['messages' => $messages]);
    }

    /**
     * Tail one turn's durable buffer as SSE — the ONE streaming response this
     * controller produces, whether the turn was just opened by {@see send()}
     * or is being resumed by {@see stream()}. The frame writing, the poll
     * loop, the deadline and the keepalive comments are all the kit's,
     * configured under `ai-kit.streaming`; nothing app-specific is folded in
     * at emit time, because the terminal `done` already carries the
     * conversation id the client needs.
     */
    private function tail(TurnBuffer $buffer, string $turnId, int $after = 0): StreamedResponse
    {
        return response()->stream(function () use ($buffer, $turnId, $after): void {
            $sse = app(SseStream::class);

            // The tail outlives a single model call: it holds the connection
            // until the turn drains or the kit's own ceiling closes it (the
            // client then reconnects with its last id).
            $sse->extendTimeLimit((int) config('ai-kit.streaming.max_stream_seconds', 180) + 30);

            $buffer->tail($turnId, $after, $sse);
        }, 200, SseStream::headers());
    }

    /**
     * The turn record, or a 404 — for an unknown or expired turn, and equally
     * for one belonging to another session. Both are 404 rather than 403: a
     * visitor has no business learning that someone else's turn id exists.
     *
     * @return array<string, mixed>
     */
    private function ownedTurn(TurnBuffer $buffer, string $turnId, string $sessionId): array
    {
        $record = $buffer->get($turnId);

        abort_if($record === null, 404);
        abort_unless(($record['meta']['session_id'] ?? null) === $sessionId, 404);

        return $record;
    }

    /**
     * Enforce the operator's per-session daily message quota (on top of the
     * route's burst limiter). Counting uses the cache-backed rate limiter
     * with a decay reaching the end of the day.
     */
    private function consumeDailyQuota(string $sessionId, AiSettings $settings): ?JsonResponse
    {
        $key = 'ai-chat-daily:'.$sessionId;
        $limit = max(1, $settings->per_session_rate_limit);

        if (RateLimiter::attempts($key) >= $limit) {
            return response()->json([
                'message' => 'وصلت إلى الحد اليومي لرسائل المساعد لهذه الجلسة. عد غداً وسيسعدنا مساعدتك.',
            ], 429);
        }

        RateLimiter::hit($key, max(60, (int) now()->secondsUntilEndOfDay()));

        return null;
    }

    /**
     * Continue only a conversation this session actually owns (ai-kit's
     * ownership guard checks participant id AND type); a foreign or unknown
     * id starts a fresh thread instead of leaking (or writing into) another
     * participant's history.
     */
    private function ownedConversationId(mixed $conversationId, string $sessionId, ConversationOwnership $ownership): ?string
    {
        if (! is_string($conversationId) || $conversationId === '') {
            return null;
        }

        return $ownership->owns($conversationId, $sessionId, SessionOwner::class)
            ? $conversationId
            : null;
    }

    /**
     * One attachment as the chat client renders it: enough to draw the chip
     * (name, type, size) and the authorized URL to open it. The stored PATH and
     * the extracted TEXT never leave the server — the path is disk-internal and
     * the extraction is model evidence, not something the visitor wrote.
     *
     * @return array<string, mixed>
     */
    private function attachmentPayload(ChatAttachment $attachment): array
    {
        return [
            'id' => (string) $attachment->id,
            'name' => (string) $attachment->original_filename,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'url' => route('ai.chat.attachments.show', ['attachment' => $attachment->id]),
        ];
    }

    /**
     * The referenced attachments that belong to this session — foreign ids
     * are silently dropped, never read.
     *
     * @param  array<int, string>|null  $attachmentIds
     * @return EloquentCollection<int, ChatAttachment>
     */
    private function ownedAttachments(?array $attachmentIds, string $sessionId): EloquentCollection
    {
        if ($attachmentIds === null || $attachmentIds === []) {
            return new EloquentCollection;
        }

        return ChatAttachment::query()
            ->whereKey($attachmentIds)
            ->where('session_id', $sessionId)
            ->get();
    }

    private function disabledResponse(): JsonResponse
    {
        return response()->json(['message' => 'المساعد الذكي غير متاح حالياً.'], 503);
    }
}
