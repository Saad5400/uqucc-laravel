<?php

namespace App\Http\Controllers\Ai;

use App\Ai\Agents\StudentAssistant;
use App\Ai\Chat\AttachmentContext;
use App\Ai\Chat\CitationExtractor;
use App\Ai\Chat\SessionOwner;
use App\Ai\Spend\SpendLedger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ChatMessageRequest;
use App\Models\Ai\ChatAttachment;
use App\Settings\AiSettings;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Streaming\Events\Error as ErrorEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
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
 * Layered gates, in order: assistant toggle (503, SearchController's
 * pattern) → daily spend budget via the SpendLedger (503) → the `ai-chat`
 * burst limiter on the route (429) → the operator's per-session daily
 * message quota (429).
 */
class ChatController extends Controller
{
    /** Spend-ledger feature key for assistant chat turns. */
    private const FEATURE = 'assistant';

    /**
     * POST /ai/chat (name: ai.chat.send) — run one assistant turn as SSE.
     */
    public function send(
        ChatMessageRequest $request,
        AiSettings $settings,
        SpendLedger $ledger,
        CitationExtractor $citations,
        AttachmentContext $attachmentContext,
    ): JsonResponse|StreamedResponse {
        if (! $settings->isFeatureEnabled('assistant')) {
            return $this->disabledResponse();
        }

        if (! $ledger->hasBudgetRemaining()) {
            return response()->json(['message' => $ledger->budgetExhaustedMessage()], 503);
        }

        $sessionId = $request->session()->getId();

        if ($quotaResponse = $this->consumeDailyQuota($sessionId, $settings)) {
            return $quotaResponse;
        }

        $conversationId = $this->ownedConversationId($request->input('conversation_id'), $sessionId);
        $attachments = $this->ownedAttachments($request->validated('attachment_ids', []), $sessionId);

        $prompt = $attachmentContext->wrap($request->validated('message'), $attachments);

        return response()->stream(
            fn () => $this->streamTurn($prompt, $sessionId, $conversationId, $attachments, $settings, $ledger, $citations),
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * GET /ai/chat/{conversation} (name: ai.chat.show) — the stored thread
     * for rehydrating the chat panel. 404 unless the current session owns it.
     */
    public function show(
        Request $request,
        AiSettings $settings,
        CitationExtractor $citations,
        AttachmentContext $attachmentContext,
        string $conversation,
    ): JsonResponse {
        if (! $settings->isFeatureEnabled('assistant')) {
            return $this->disabledResponse();
        }

        $owned = Conversation::query()
            ->whereKey($conversation)
            ->where('user_id', $request->session()->getId())
            ->exists();

        abort_unless($owned, 404);

        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation)
            ->orderBy('id')
            ->get()
            ->map(fn (ConversationMessage $message): array => [
                'role' => (string) $message->getAttribute('role'),
                'content' => $message->getAttribute('role') === 'user'
                    ? $attachmentContext->unwrap((string) $message->getAttribute('content'))
                    : (string) $message->getAttribute('content'),
                'citations' => $message->getAttribute('role') === 'assistant'
                    ? $citations->extractFromStored((array) $message->getAttribute('tool_results'))
                    : [],
                'created_at' => $message->getAttribute('created_at')?->toIso8601String(),
            ])
            ->values();

        return response()->json(['messages' => $messages]);
    }

    /**
     * Run the turn against the model and emit the SSE events. Runs inside the
     * streamed response, so every outcome — including a thrown provider
     * error — must land as an event the client understands.
     *
     * @param  EloquentCollection<int, ChatAttachment>  $attachments
     */
    private function streamTurn(
        string $prompt,
        string $sessionId,
        ?string $conversationId,
        EloquentCollection $attachments,
        AiSettings $settings,
        SpendLedger $ledger,
        CitationExtractor $citations,
    ): void {
        set_time_limit((int) config('ai.chat.timeout', 60) + 30);

        $ledger->clearContextCosts();

        $owner = new SessionOwner($sessionId);

        $agent = StudentAssistant::make();
        $agent = $conversationId !== null
            ? $agent->continue($conversationId, $owner)
            : $agent->forUser($owner);

        try {
            $response = $agent->stream($prompt);

            $toolResults = [];

            foreach ($response as $event) {
                if ($event instanceof TextDelta) {
                    $this->emit('delta', ['text' => $event->delta]);
                } elseif ($event instanceof ToolResultEvent) {
                    $toolResults[] = $event->toolResult;
                } elseif ($event instanceof ErrorEvent) {
                    $this->recordSpend($ledger, $settings, $response->usage ?? null);
                    $this->emit('error', ['message' => $this->genericErrorMessage()]);

                    return;
                }
            }

            $this->recordSpend($ledger, $settings, $response->usage ?? null);

            $items = $citations->extract($toolResults);

            if ($items !== []) {
                $this->emit('citations', ['items' => $items]);
            }

            $finalConversationId = $response->conversationId ?? $conversationId;

            if ($finalConversationId !== null && $attachments->isNotEmpty()) {
                ChatAttachment::query()
                    ->whereKey($attachments->modelKeys())
                    ->update(['conversation_id' => $finalConversationId]);
            }

            $this->emit('done', [
                'conversation_id' => $finalConversationId,
                'message_id' => $this->latestAssistantMessageId($finalConversationId),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $this->recordSpend($ledger, $settings, null);
            $this->emit('error', ['message' => $this->genericErrorMessage()]);
        }
    }

    /**
     * Record the turn's exact provider spend (streamed rounds summed from the
     * gateway's Context costs) on the ledger. Never fails the turn.
     */
    private function recordSpend(SpendLedger $ledger, AiSettings $settings, ?\Laravel\Ai\Responses\Data\Usage $usage): void
    {
        try {
            $ledger->record(self::FEATURE, trim($settings->chat_model), $usage, $ledger->captureContextCosts());
        } catch (Throwable $exception) {
            report($exception);
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
     * Continue only a conversation this session actually owns; a foreign or
     * unknown id starts a fresh thread instead of leaking (or writing into)
     * another visitor's history.
     */
    private function ownedConversationId(mixed $conversationId, string $sessionId): ?string
    {
        if (! is_string($conversationId) || $conversationId === '') {
            return null;
        }

        $owned = Conversation::query()
            ->whereKey($conversationId)
            ->where('user_id', $sessionId)
            ->exists();

        return $owned ? $conversationId : null;
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

    /**
     * Write one SSE event frame and flush it to the client immediately.
     *
     * @param  array<string, mixed>  $data
     */
    private function emit(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";

        if (app()->runningUnitTests()) {
            return;
        }

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
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
