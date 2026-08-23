<?php

namespace App\Jobs\Ai;

use App\Ai\Agents\StudentAssistant;
use App\Ai\Chat\AnswerLinkGuard;
use App\Ai\Chat\CitationExtractor;
use App\Ai\Chat\SessionOwner;
use App\Ai\Chat\StreamingAnswerLinkGuard;
use App\Models\Ai\ChatAttachment;
use Generator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Saad\AiKit\Safety\KillSwitch;
use Saad\AiKit\Streaming\StreamEventMapper;
use Saad\AiKit\Streaming\StreamResult;
use Saad\AiKit\Streaming\TurnBuffer;
use Throwable;

/**
 * Runs one student-assistant turn in the background, appending its wire
 * events to the kit's durable {@see TurnBuffer} instead of writing them
 * straight to a response. The SSE endpoints only ever TAIL that buffer, so a
 * visitor who loses connectivity, backgrounds the tab or reloads the page
 * resumes the same turn from `?cursor=<last seq>` rather than losing it —
 * and the reply keeps generating either way, because nothing about it is tied
 * to an open socket any more.
 *
 * The fold from laravel/ai stream events to wire events is unchanged from the
 * in-request path this replaces: the kit's {@see StreamEventMapper} with the
 * link guard on the text pipeline and citations emitted from the turn's tool
 * results, producing the same `reasoning` / `delta` / `tool` / `citations` /
 * `done` sequence the client already reads. `runIntoBuffer()` writes the
 * turn's ONE terminal event through the buffer, so a replayed turn and a live
 * one are byte-identical.
 *
 * Two pieces are app-side because the kit primitive cannot express them:
 *
 *  - CANCELLATION: {@see untilCancelled} wraps the stream as a generator IN
 *    FRONT of the mapper, since the mapper folds a whole iterable with no
 *    stop hook. The visitor's stop button now flags the turn server-side
 *    (the buffer's own cancel key) rather than merely hanging up — with
 *    generation decoupled from the request, hanging up no longer stops it.
 *  - THE THROWN failure: a provider error the mapper never sees (it was
 *    raised building or opening the stream) fails the turn here, with the
 *    same generic line the mapper's `onError` produces.
 */
class GenerateChatReply implements ShouldQueue
{
    use Queueable;

    /**
     * The usage label every model call in a student turn is recorded under,
     * and the kill-switch scope that darkens the surface. It lives on the job
     * because the job is what actually spends; ChatController imports it for
     * its pre-flight guard.
     */
    public const FEATURE = 'assistant';

    /** One attempt: a half-streamed reply must never silently replay. */
    public int $tries = 1;

    /**
     * @param  list<string>  $attachmentIds  the session's own attachments, bound to the
     *                                       conversation once the turn resolves one
     */
    public function __construct(
        public string $turnId,
        public string $sessionId,
        public string $prompt,
        public ?string $conversationId = null,
        public array $attachmentIds = [],
    ) {
        $this->onQueue((string) config('ai.chat.queue', 'default'));
    }

    public function handle(TurnBuffer $buffer, KillSwitch $killSwitch, CitationExtractor $citations): void
    {
        // The request already guarded this turn, but that was before it was
        // queued — possibly long before, and a kill switch engaged mid-incident
        // exists precisely to stop a queued backlog spending its way through.
        // Re-check here, where the model call actually happens. The daily
        // budget is deliberately NOT re-checked: a visitor already told their
        // turn would run should not lose it to someone else's spend.
        if ($killSwitch->engaged(self::FEATURE)) {
            $buffer->fail($this->turnId, 'المساعد الذكي غير متاح حالياً.');

            return;
        }

        // ai-kit's usage module records the turn (exact provider cost, tokens,
        // timings) automatically; the label is all it needs.
        Context::add((string) config('ai-kit.usage.feature_context_key'), self::FEATURE);

        $owner = new SessionOwner($this->sessionId);

        $agent = StudentAssistant::make();
        $agent = $this->conversationId !== null
            ? $agent->continue($this->conversationId, $owner)
            : $agent->forUser($owner);

        try {
            $response = $agent->stream($this->prompt);

            (new StreamEventMapper)
                ->transformText(new StreamingAnswerLinkGuard(app(AnswerLinkGuard::class)))
                ->onError(fn (): string => $this->genericErrorMessage())
                ->beforeDone(function (StreamResult $result, callable $emit) use ($citations): void {
                    $items = $citations->extract($result->toolResults);

                    if ($items !== []) {
                        $emit('citations', ['items' => $items]);
                    }
                })
                ->doneUsing(function () use ($response): array {
                    $finalConversationId = $response->conversationId ?? $this->conversationId;

                    $this->bindAttachments($finalConversationId);

                    return [
                        'conversation_id' => $finalConversationId,
                        'message_id' => $this->latestAssistantMessageId($finalConversationId),
                    ];
                })
                // No `$meta`: `runIntoBuffer` fixes it before the fold, and the
                // only fact the stream and cancel endpoints read back — the
                // owning session — was recorded when the controller opened the
                // turn. The conversation id a fresh thread resolves rides the
                // `done` payload instead, which IS assembled after the fold.
                ->runIntoBuffer($this->untilCancelled($response, $buffer), $buffer, $this->turnId);
        } catch (Throwable $exception) {
            report($exception);

            $buffer->fail($this->turnId, $this->genericErrorMessage());
        }
    }

    /**
     * The agent's stream, cut short the moment the visitor's stop lands.
     *
     * The kit's mapper folds any `iterable` and has no stop hook of its own,
     * so cancellation composes as a generator IN FRONT of it: when the flag
     * flips this simply stops yielding, the mapper's fold ends the way a
     * drained stream ends (flushing held text, running `beforeDone`,
     * assembling `done`), and whatever streamed so far is what the turn
     * finishes with.
     *
     * The flag lives under its own cache key, so a stop can never race the
     * appends this job is making. It is polled at most once a second — never a
     * per-token cache hammer — and the 0.0 seed makes the FIRST event check,
     * so a stop pressed before anything streamed still lands.
     *
     * @param  iterable<StreamEvent>  $stream
     * @return Generator<StreamEvent>
     */
    private function untilCancelled(iterable $stream, TurnBuffer $buffer): Generator
    {
        $lastCheck = 0.0;

        foreach ($stream as $event) {
            yield $event;

            if (microtime(true) - $lastCheck < 1.0) {
                continue;
            }

            $lastCheck = microtime(true);

            if ($buffer->isCancelled($this->turnId)) {
                return;
            }
        }
    }

    /**
     * Anchor the turn's attachments to the conversation it resolved AND to the
     * user message they were sent with, so a rehydrated thread puts the files
     * back on the right bubble instead of on every bubble.
     *
     * The message id is read back rather than handed down because nothing on
     * the streaming path surfaces it — the store writes the user message while
     * the turn is running. The read is safe because this job is the
     * conversation's only writer while it holds the turn, so the newest user
     * row IS this turn's. A turn that never got that far leaves its rows
     * unanchored, which renders as no chip: correct, because no stored message
     * ever carried them.
     */
    private function bindAttachments(?string $conversationId): void
    {
        if ($conversationId === null || $this->attachmentIds === []) {
            return;
        }

        ChatAttachment::query()
            ->whereKey($this->attachmentIds)
            ->ownedBySession($this->sessionId)
            ->update([
                'conversation_id' => $conversationId,
                'message_id' => $this->latestUserMessageId($conversationId),
            ]);
    }

    private function latestUserMessageId(string $conversationId): ?string
    {
        return ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->value('id');
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

    private function genericErrorMessage(): string
    {
        return 'حدث خطأ أثناء توليد الرد. حاول مرة أخرى.';
    }

    /**
     * A turn whose queue worker died outright (timeout, OOM) still owes its
     * client a terminal event — without one the buffer stays `running` and the
     * tail loop keeps the connection open until the deadline.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Assistant turn failed', [
            'turn_id' => $this->turnId,
            'exception' => $exception?->getMessage(),
        ]);

        app(TurnBuffer::class)->fail($this->turnId, $this->genericErrorMessage());
    }
}
