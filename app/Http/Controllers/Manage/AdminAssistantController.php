<?php

namespace App\Http\Controllers\Manage;

use App\Ai\Admin\AdminOwner;
use App\Ai\Admin\AssistantCards;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\AdminAssistantDecisionRequest;
use App\Http\Requests\Manage\AdminAssistantMessageRequest;
use App\Jobs\Ai\GenerateAdminAssistantReply;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Laravel\Ai\Models\ConversationMessage;
use Saad\AiKit\Approvals\Classified\ResumeDecisions;
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
 * The /manage admin assistant chat API — the operator copilot whose writes
 * pause the turn on ai-kit's classified approval seam. The transport mirrors
 * the public {@see \App\Http\Controllers\Ai\ChatController} SSE contract
 * (turn/reasoning/delta/tool/done/error) plus an `approval` or `question`
 * event per card whenever the turn paused, so the client renders تأكيد/رفض
 * cards inline. Conversations belong to the authenticated admin (AdminOwner,
 * "admin:{id}").
 *
 * ── Resumable turns ── Neither {@see send()} nor {@see decide()} generates
 * inside the request any more: each opens a durable {@see TurnBuffer} turn,
 * queues {@see GenerateAdminAssistantReply} to fold the model stream into it,
 * and then TAILS that buffer. Both still answer as SSE over the same event
 * contract, so the panel's transport is unchanged — but every frame now
 * carries an `id:` line, a leading `turn {id}` frame hands the client the
 * handle, and an operator whose connection drops mid-write reconnects to
 * {@see stream()} with `?cursor=<last seq>` instead of losing the turn.
 *
 * A resume mints a NEW turn (the paused one finished when it paused), which
 * is invisible to the client: it reads the same stream off the same POST.
 * {@see decide()} stays keyed by CONVERSATION rather than by turn — the
 * server's pause markers live on the conversation, so nothing about resuming
 * has to look up a turn record, and the endpoint's URL, payload and status
 * codes are exactly what the panel already speaks.
 *
 * `reasoning` and `tool` are ai-kit v0.5.0 defaults and are deliberately
 * left on: the panel shows thinking as a collapsible block and tool progress
 * as chips. Neither carries arguments or results, and neither is persisted —
 * a rehydrated thread shows the answer and its pending cards alone. A paused
 * call emits `tool {status: running}` and no `done`; its `approval` card
 * carries the SAME id, and the client folds the chip into the card.
 *
 * Layered gates on every endpoint: panel auth (route middleware) → ai-kit's
 * kill switch, which folds master ai_enabled AND admin_assistant_enabled
 * (503 with the reason) with the kit's cache switch → daily spend budget on
 * turn entry (503, via TurnGuard) → the route's per-admin burst limiter
 * (429). The queued job re-checks the kill switch on its way out of the
 * queue, which is where the money — and the writes — actually land.
 */
class AdminAssistantController extends Controller
{
    /** Usage feature label for admin assistant turns (ai-kit usage module). */
    private const FEATURE = GenerateAdminAssistantReply::FEATURE;

    /**
     * GET /manage/assistant (name: manage.assistant.index) — the chat page.
     * Always renders; when the feature is off the page explains how to
     * enable it instead of hiding (disabled-with-reason).
     */
    public function index(AiSettings $settings, KillSwitch $killSwitch): Response
    {
        return Inertia::render('manage/assistant/Index', [
            'assistant' => [
                'enabled' => ! $killSwitch->engaged(self::FEATURE),
                'disabledReason' => $this->disabledReason($settings),
            ],
        ]);
    }

    /**
     * POST /manage/assistant/chat (name: manage.assistant.send) — run one
     * assistant turn as SSE. Pre-flight failures answer as plain JSON.
     */
    public function send(
        AdminAssistantMessageRequest $request,
        AiSettings $settings,
        TurnGuard $guard,
        ConversationOwnership $ownership,
        TurnBuffer $buffer,
    ): JsonResponse|StreamedResponse {
        try {
            $guard->check(self::FEATURE);
        } catch (AiKilledException) {
            return $this->disabledResponse($settings);
        } catch (AiUnavailableException $exception) {
            return response()->json(['message' => $exception->userFacingReason()], 503);
        }

        /** @var User $admin */
        $admin = $request->user();

        $owner = new AdminOwner($admin);
        $conversationId = $this->ownedConversationId($request->input('conversation_id'), $owner, $ownership);

        return $this->startTurn($buffer, $admin, $conversationId, prompt: (string) $request->validated('message'));
    }

    /**
     * GET /manage/assistant/turns/{turn}/stream (name: manage.assistant.stream)
     * — resume a turn from `?cursor=<last seq>` (or `Last-Event-ID`). Replaying
     * a buffer spends nothing, so only ownership gates it; an unknown or
     * expired turn is a 404 so the client stops retrying.
     */
    public function stream(Request $request, TurnBuffer $buffer, string $turn): StreamedResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $this->ownedTurn($buffer, $turn, (int) $admin->id);

        $after = max(0, (int) $request->query('cursor', $request->header('Last-Event-ID', '0')));

        return $this->tail($buffer, $turn, $after);
    }

    /**
     * POST /manage/assistant/turns/{turn}/cancel (name:
     * manage.assistant.cancel) — the admin pressed stop. Generation runs in a
     * queued job now, so hanging up the SSE connection no longer ends it: flag
     * the turn and the job finishes early with whatever it had. A stop that
     * lands after a write already paused leaves that pause standing —
     * {@see decide()} re-checks the server's pending set regardless.
     */
    public function cancel(Request $request, TurnBuffer $buffer, string $turn): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $this->ownedTurn($buffer, $turn, (int) $admin->id);

        $buffer->cancel($turn);

        return response()->json(['cancelled' => true]);
    }

    /**
     * POST /manage/assistant/chat/{conversation}/decide
     * (name: manage.assistant.decide) — resolve a paused turn's approval /
     * question cards and stream the continuation. The payload maps each
     * pending tool-call id to approve / reject / edit; every pending call
     * must be decided in one batch, because the vendor loop rejects any it
     * does not find a decision for. An edit's arguments are reconciled
     * against the server's own pending call before anything executes.
     */
    public function decide(
        AdminAssistantDecisionRequest $request,
        AiSettings $settings,
        TurnGuard $guard,
        ConversationOwnership $ownership,
        AssistantCards $cards,
        TurnBuffer $buffer,
        string $conversation,
    ): JsonResponse|StreamedResponse {
        try {
            $guard->check(self::FEATURE);
        } catch (AiKilledException) {
            return $this->disabledResponse($settings);
        } catch (AiUnavailableException $exception) {
            return response()->json(['message' => $exception->userFacingReason()], 503);
        }

        /** @var User $admin */
        $admin = $request->user();
        $owner = new AdminOwner($admin);

        abort_unless($ownership->owns($conversation, $owner->id, AdminOwner::class), 404);

        /** @var array<string, mixed> $input */
        $input = $request->validated('decisions');

        // Read the server's pause markers ONCE: the same set answers the 409
        // check below and backs the edit guard, so a card cannot be checked
        // against one snapshot and guarded against another.
        $pending = $cards->pending($conversation);
        $pendingCards = $cards->cardsFor($pending);
        $pendingIds = $pendingCards->pluck('id');

        // The 409 whose body is the CURRENT pending set, so a stale client
        // (double submit, another tab already decided) repaints instead of
        // guessing.
        if ($pendingIds->isEmpty()
            || $pendingIds->diff(array_keys($input))->isNotEmpty()
            || collect(array_keys($input))->diff($pendingIds)->isNotEmpty()) {
            return response()->json([
                'message' => 'هذه البطاقات لم تعد بانتظار قرار.',
                'pending_approvals' => $pendingCards->values(),
            ], 409);
        }

        try {
            // The guard runs HERE, where the server's own copy of the pending
            // call is in hand: an edit's readonly and hidden fields are
            // restored from the pause before the decision is serialized into a
            // job, so what the queue carries is never the raw browser payload.
            // `guarded()` also round-trips the result through the kit's own
            // parser, so a shape the resume could not read throws in THIS
            // request rather than inside the job.
            $guarded = ResumeDecisions::guarded($input, $cards->editGuard($pending));
        } catch (InvalidArgumentException) {
            // Covers both a malformed decision shape and an edit that invents
            // an argument the card never carried; a tampered readonly field
            // needs no error, because the guard silently restores it.
            return response()->json(['message' => 'صيغة القرارات غير صالحة أو لا تطابق حقول البطاقة.'], 422);
        }

        // A resume is a NEW turn — the paused one finished when it paused — but
        // that is invisible to the client, which reads the continuation off
        // this same response exactly as it always has.
        return $this->startTurn($buffer, $admin, $conversation, decisions: $guarded);
    }

    /**
     * GET /manage/assistant/chat/{conversation} (name: manage.assistant.show)
     * — the stored thread for rehydrating the panel, plus the still-pending
     * approval/question cards of a paused turn. 404 unless the admin owns
     * the thread.
     */
    public function show(
        Request $request,
        AiSettings $settings,
        KillSwitch $killSwitch,
        ConversationOwnership $ownership,
        AssistantCards $cards,
        string $conversation,
    ): JsonResponse {
        if ($killSwitch->engaged(self::FEATURE)) {
            return $this->disabledResponse($settings);
        }

        /** @var User $admin */
        $admin = $request->user();

        abort_unless(
            $ownership->owns($conversation, (new AdminOwner($admin))->id, AdminOwner::class),
            404,
        );

        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation)
            ->orderBy('id')
            ->get()
            ->map(fn (ConversationMessage $message): array => [
                'role' => (string) $message->getAttribute('role'),
                'content' => (string) ConversationContent::reveal($message->getAttribute('content')),
                'created_at' => $message->getAttribute('created_at')?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'messages' => $messages,
            'pending_approvals' => $cards->pendingFor($conversation)->values(),
        ]);
    }

    /**
     * Open one turn — a fresh prompt or a decided resume — and stream it: a
     * durable buffer, a queued job to fold the model stream into it, and the
     * tail of that buffer as the response. The two entry points differ only in
     * what they hand the job, so the turn id, the meta and the leading `turn`
     * frame are minted in one place.
     *
     * @param  array<string, mixed>|null  $decisions
     */
    private function startTurn(
        TurnBuffer $buffer,
        User $admin,
        ?string $conversationId,
        string $prompt = '',
        ?array $decisions = null,
    ): StreamedResponse {
        $turnId = (string) Str::uuid7();

        // `admin_id` is what {@see stream()} and {@see cancel()} read back to
        // refuse a foreign turn.
        $buffer->start($turnId, [
            'admin_id' => (int) $admin->id,
            'conversation_id' => $conversationId,
        ]);

        // The handle the client stores so it can resume this turn. Appended
        // BEFORE the job is dispatched, so it is seq 1 and no worker can be
        // appending concurrently — the buffer's single-writer rule holds.
        $buffer->append($turnId, 'turn', ['id' => $turnId]);

        GenerateAdminAssistantReply::dispatch(
            turnId: $turnId,
            adminId: (int) $admin->id,
            prompt: $prompt,
            conversationId: $conversationId,
            decisions: $decisions,
        );

        return $this->tail($buffer, $turnId);
    }

    /**
     * Tail one turn's durable buffer as SSE — the ONE streaming response this
     * controller produces, whether the turn was just opened by {@see send()}
     * or {@see decide()}, or is being resumed by {@see stream()}. The frame
     * writing, the poll loop, the deadline and the keepalive comments are all
     * the kit's, configured under `ai-kit.streaming`.
     */
    private function tail(TurnBuffer $buffer, string $turnId, int $after = 0): StreamedResponse
    {
        return response()->stream(function () use ($buffer, $turnId, $after): void {
            $sse = app(SseStream::class);

            $sse->extendTimeLimit((int) config('ai-kit.streaming.max_stream_seconds', 180) + 30);

            $buffer->tail($turnId, $after, $sse);
        }, 200, SseStream::headers());
    }

    /**
     * The turn record, or a 404 — for an unknown or expired turn, and equally
     * for one belonging to another admin. Both are 404 rather than 403: no
     * admin needs to learn that a colleague's turn id exists.
     *
     * @return array<string, mixed>
     */
    private function ownedTurn(TurnBuffer $buffer, string $turnId, int $adminId): array
    {
        $record = $buffer->get($turnId);

        abort_if($record === null, 404);
        abort_unless((int) ($record['meta']['admin_id'] ?? 0) === $adminId, 404);

        return $record;
    }

    /**
     * Continue only a conversation this admin actually owns (ai-kit's
     * ownership guard checks participant id AND type); a foreign or unknown
     * id starts a fresh thread instead of leaking (or writing into) another
     * participant's history.
     */
    private function ownedConversationId(mixed $conversationId, AdminOwner $owner, ConversationOwnership $ownership): ?string
    {
        if (! is_string($conversationId) || $conversationId === '') {
            return null;
        }

        return $ownership->owns($conversationId, $owner->id, AdminOwner::class)
            ? $conversationId
            : null;
    }

    private function disabledResponse(AiSettings $settings): JsonResponse
    {
        return response()->json(['message' => $this->disabledReason($settings) ?? 'المساعد الإداري غير متاح حالياً.'], 503);
    }

    /**
     * Why the assistant is unavailable, for disabled-with-reason UX — null
     * while it is enabled.
     */
    private function disabledReason(AiSettings $settings): ?string
    {
        if (! $settings->ai_enabled) {
            return 'الذكاء الاصطناعي معطل بالكامل. فعّل «تفعيل الذكاء الاصطناعي» من صفحة الإعدادات أولاً.';
        }

        if (! $settings->admin_assistant_enabled) {
            return 'المساعد الإداري معطل. فعّل «المساعد الإداري» من صفحة الإعدادات لاستخدامه.';
        }

        return null;
    }
}
