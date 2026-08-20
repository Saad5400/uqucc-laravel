<?php

namespace App\Jobs\Ai;

use App\Ai\Admin\AdminAssistant;
use App\Ai\Admin\AdminOwner;
use App\Ai\Admin\AssistantCards;
use App\Models\User;
use Generator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Saad\AiKit\Approvals\Classified\ResumeDecisions;
use Saad\AiKit\Safety\KillSwitch;
use Saad\AiKit\Streaming\StreamEventMapper;
use Saad\AiKit\Streaming\TurnBuffer;
use Throwable;

/**
 * Runs one admin-assistant turn in the background, appending its wire events
 * to the kit's durable {@see TurnBuffer} rather than writing them straight to
 * a response — the same resumable shape as the student surface's
 * {@see GenerateChatReply}, so an operator who loses connectivity mid-write
 * reconnects to the SAME turn instead of losing it.
 *
 * The fold is unchanged from the in-request path this replaces: the kit's
 * {@see StreamEventMapper} with {@see AssistantCards} hooked onto it, so a
 * gated write still emits its `approval` / `question` card and the turn still
 * ends there with nothing written. What changed is only WHERE the events go.
 *
 * ── Resume turns ── A decided batch does not continue the paused job (it
 * finished when the turn paused); it mints a NEW turn that prompts the same
 * conversation with the decisions. The vendor loop replays the paused calls,
 * executes the approved ones and carries on. That continuation may itself
 * pause again, so one logical exchange can span several turns.
 *
 * ── Acting user ── Every write the turn performs runs as the admin who asked
 * for it, so the guard is swapped for the turn's duration and restored after
 * (Octane-safe): the tools' own authorization, the activity-log causer and
 * every policy read the right user.
 */
class GenerateAdminAssistantReply implements ShouldQueue
{
    use Queueable;

    /**
     * The usage label every model call in an admin turn is recorded under,
     * and the kill-switch scope that darkens the surface (it folds the master
     * ai_enabled AND admin_assistant_enabled toggles).
     */
    public const FEATURE = 'admin_assistant';

    /** One attempt: a half-streamed turn must never silently replay its writes. */
    public int $tries = 1;

    /**
     * @param  array<string, mixed>|null  $decisions  a RESUME turn's card decisions, already
     *                                                reconciled against the server's pending
     *                                                calls by the decide endpoint
     */
    public function __construct(
        public string $turnId,
        public int $adminId,
        public string $prompt = '',
        public ?string $conversationId = null,
        public ?array $decisions = null,
    ) {
        $this->onQueue((string) config('ai.chat.queue', 'default'));
    }

    public function handle(TurnBuffer $buffer, KillSwitch $killSwitch): void
    {
        // The request guarded this turn before it was queued; a kill switch
        // engaged mid-incident exists precisely to stop a queued backlog
        // spending — and, here, writing — its way through. The daily budget is
        // deliberately NOT re-checked: an admin already told their turn would
        // run should not lose it to someone else's spend.
        if ($killSwitch->engaged(self::FEATURE)) {
            $buffer->fail($this->turnId, 'المساعد الإداري غير متاح حالياً.');

            return;
        }

        $admin = User::query()->find($this->adminId);

        if ($admin === null) {
            $buffer->fail($this->turnId, $this->genericErrorMessage());

            return;
        }

        // ai-kit's usage module records the turn (exact provider cost, tokens,
        // timings) automatically; the label is all it needs.
        Context::add((string) config('ai-kit.usage.feature_context_key'), self::FEATURE);

        // Authenticate the acting admin for the WHOLE turn so every policy,
        // scope and audit causer that reads auth()->user() is correct, then
        // restore the prior guard — the worker is long-lived and must not leak
        // one turn's user into the next.
        $auth = Auth::guard();
        $previousUser = $auth->hasUser() ? $auth->user() : null;
        $auth->setUser($admin);

        try {
            $owner = new AdminOwner($admin);

            $agent = AdminAssistant::make();
            $agent = $this->conversationId !== null
                ? $agent->continue($this->conversationId, $owner)
                : $agent->forUser($owner);

            // A RESUME turn prompts with the admin's decisions instead of new
            // text. The payload was already reconciled against the server's own
            // pending calls before it was queued, so what arrives here is never
            // the raw browser body.
            $prompt = $this->decisions !== null
                ? ResumeDecisions::fromClient($this->decisions)
                : $this->prompt;

            $response = $agent->stream($prompt);

            app(AssistantCards::class)
                ->attachTo(
                    (new StreamEventMapper)
                        ->onError(fn (): string => $this->genericErrorMessage())
                        ->doneUsing(fn (): array => [
                            'conversation_id' => $response->conversationId ?? $this->conversationId,
                        ])
                )
                // No `$meta`: the only fact the stream and cancel endpoints read
                // back — the owning admin — was recorded when the controller
                // opened the turn, and the decide endpoint is keyed by
                // CONVERSATION rather than by turn, so it never reads turn meta
                // at all. The conversation id a fresh thread resolves rides the
                // `done` payload, which IS assembled after the fold.
                ->runIntoBuffer($this->untilCancelled($response, $buffer), $buffer, $this->turnId);
        } catch (Throwable $exception) {
            report($exception);

            $buffer->fail($this->turnId, $this->genericErrorMessage());
        } finally {
            $previousUser !== null ? $auth->setUser($previousUser) : Auth::forgetGuards();
        }
    }

    /**
     * The agent's stream, cut short the moment the admin's stop lands.
     *
     * The kit's mapper folds any `iterable` and has no stop hook of its own,
     * so cancellation composes as a generator IN FRONT of it: when the flag
     * flips this stops yielding, and the fold ends the way a drained stream
     * ends. A stop that lands after a write already paused leaves that pause
     * standing — the decide endpoint re-checks the server's pending set before
     * anything executes, so a half-decided batch cannot slip through.
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

    private function genericErrorMessage(): string
    {
        return 'حدث خطأ أثناء توليد الرد. حاول مرة أخرى.';
    }

    /**
     * A turn whose queue worker died outright (timeout, OOM) still owes its
     * client a terminal event — without one the buffer stays `running` and the
     * tail loop holds the connection open until its deadline.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Admin assistant turn failed', [
            'turn_id' => $this->turnId,
            'admin_id' => $this->adminId,
            'exception' => $exception?->getMessage(),
        ]);

        app(TurnBuffer::class)->fail($this->turnId, $this->genericErrorMessage());
    }
}
