<?php

namespace App\Http\Controllers\Ai;

use App\Ai\Agents\StudentAssistant;
use App\Ai\Chat\AnswerLinkGuard;
use App\Ai\Chat\AttachmentContext;
use App\Ai\Chat\CategoryContext;
use App\Ai\Chat\CitationExtractor;
use App\Ai\Chat\SessionOwner;
use App\Ai\Chat\StreamingAnswerLinkGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ChatMessageRequest;
use App\Models\Ai\ChatAttachment;
use App\Settings\AiSettings;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\Models\ConversationMessage;
use Saad\AiKit\Conversations\ConversationContent;
use Saad\AiKit\Conversations\ConversationOwnership;
use Saad\AiKit\Safety\Exceptions\AiKilledException;
use Saad\AiKit\Safety\Exceptions\AiUnavailableException;
use Saad\AiKit\Safety\KillSwitch;
use Saad\AiKit\Safety\TurnGuard;
use Saad\AiKit\Streaming\SseStream;
use Saad\AiKit\Streaming\StreamEventMapper;
use Saad\AiKit\Streaming\StreamResult;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The anonymous student-assistant chat API. Visitors have no accounts, so a
 * conversation belongs to the SESSION that started it (SessionOwner wraps the
 * session id as the laravel/ai conversation participant).
 *
 * POST /ai/chat answers as a Server-Sent-Events stream — TRUE streaming: the
 * LLM call runs inside the request and each model text delta is flushed as a
 * `delta` event, followed by `citations` (pages the turn's content tools
 * consulted), then `done`, or `error`. Pre-flight failures (feature toggle,
 * budget, daily quota) answer as plain JSON before any stream starts.
 *
 * Layered gates, in order: ai-kit's TurnGuard (503) — the assistant toggle
 * via the operator's AiSettings, the kit's cache kill switch, and the daily
 * spend budget in one call — → the `ai-chat` burst limiter on the route
 * (429) → the operator's per-session daily message quota (429).
 */
class ChatController extends Controller
{
    /** Usage feature label for assistant chat turns (ai-kit usage module). */
    private const FEATURE = 'assistant';

    /**
     * POST /ai/chat (name: ai.chat.send) — run one assistant turn as SSE.
     */
    public function send(
        ChatMessageRequest $request,
        AiSettings $settings,
        TurnGuard $guard,
        ConversationOwnership $ownership,
        CitationExtractor $citations,
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

        return response()->stream(
            fn () => $this->streamTurn($prompt, $sessionId, $conversationId, $attachments, $citations),
            200,
            SseStream::headers(),
        );
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

        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation)
            ->orderBy('id')
            ->get()
            ->map(fn (ConversationMessage $message): array => [
                'role' => (string) $message->getAttribute('role'),
                // Wrappers unwind outside-in: the category block wraps the
                // attachment block wraps what the visitor actually typed.
                'content' => $message->getAttribute('role') === 'user'
                    ? $attachmentContext->unwrap($categoryContext->unwrap((string) ConversationContent::reveal($message->getAttribute('content'))))
                    : $linkGuard->sanitize((string) ConversationContent::reveal($message->getAttribute('content'))),
                'citations' => $message->getAttribute('role') === 'assistant'
                    ? $citations->extractFromStored((array) $message->getAttribute('tool_results'))
                    : [],
                'created_at' => $message->getAttribute('created_at')?->toIso8601String(),
            ])
            ->values();

        return response()->json(['messages' => $messages]);
    }

    /**
     * Run the turn against the model and emit the SSE events, folded through
     * ai-kit's StreamEventMapper (the link guard rides the text pipeline,
     * citations ride beforeDone). Runs inside the streamed response, so every
     * outcome — including a thrown provider error — must land as an event
     * the client understands.
     *
     * @param  EloquentCollection<int, ChatAttachment>  $attachments
     */
    private function streamTurn(
        string $prompt,
        string $sessionId,
        ?string $conversationId,
        EloquentCollection $attachments,
        CitationExtractor $citations,
    ): void {
        $sse = new SseStream;
        $sse->extendTimeLimit((int) config('ai.chat.timeout', 60) + 30);

        // ai-kit's usage module records the turn (exact provider cost,
        // tokens, timings) automatically; the label is all it needs.
        Context::add(config('ai-kit.usage.feature_context_key'), self::FEATURE);

        $owner = new SessionOwner($sessionId);

        $agent = StudentAssistant::make();
        $agent = $conversationId !== null
            ? $agent->continue($conversationId, $owner)
            : $agent->forUser($owner);

        try {
            $response = $agent->stream($prompt);

            (new StreamEventMapper)
                ->transformText(new StreamingAnswerLinkGuard(app(AnswerLinkGuard::class)))
                ->onError(fn (): string => $this->genericErrorMessage())
                ->beforeDone(function (StreamResult $result, callable $emit) use ($citations): void {
                    $items = $citations->extract($result->toolResults);

                    if ($items !== []) {
                        $emit('citations', ['items' => $items]);
                    }
                })
                ->doneUsing(function () use ($response, $conversationId, $attachments): array {
                    $finalConversationId = $response->conversationId ?? $conversationId;

                    if ($finalConversationId !== null && $attachments->isNotEmpty()) {
                        ChatAttachment::query()
                            ->whereKey($attachments->modelKeys())
                            ->update(['conversation_id' => $finalConversationId]);
                    }

                    return [
                        'conversation_id' => $finalConversationId,
                        'message_id' => $this->latestAssistantMessageId($finalConversationId),
                    ];
                })
                ->run($response, fn (string $event, array $data) => $sse->emit($event, $data));
        } catch (Throwable $exception) {
            report($exception);

            $sse->emit('error', ['message' => $this->genericErrorMessage()]);
        }
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

    private function latestAssistantMessageId(?string $conversationId): ?string
    {
        if ($conversationId === null) {
            return null;
        }

        return ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->value('id');
    }

    private function disabledResponse(): JsonResponse
    {
        return response()->json(['message' => 'المساعد الذكي غير متاح حالياً.'], 503);
    }

    private function genericErrorMessage(): string
    {
        return 'حدث خطأ أثناء توليد الرد. حاول مرة أخرى.';
    }
}
