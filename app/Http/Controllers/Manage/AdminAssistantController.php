<?php

namespace App\Http\Controllers\Manage;

use App\Ai\Admin\AdminAssistant;
use App\Ai\Admin\AdminOwner;
use App\Ai\Admin\AssistantCards;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\AdminAssistantDecisionRequest;
use App\Http\Requests\Manage\AdminAssistantMessageRequest;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Models\ConversationMessage;
use Saad\AiKit\Approvals\Classified\ResumeDecisions;
use Saad\AiKit\Conversations\ConversationContent;
use Saad\AiKit\Conversations\ConversationOwnership;
use Saad\AiKit\Safety\Exceptions\AiKilledException;
use Saad\AiKit\Safety\Exceptions\AiUnavailableException;
use Saad\AiKit\Safety\KillSwitch;
use Saad\AiKit\Safety\TurnGuard;
use Saad\AiKit\Streaming\SseStream;
use Saad\AiKit\Streaming\StreamEventMapper;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The /manage admin assistant chat API — the operator copilot whose writes
 * pause the turn on ai-kit's classified approval seam. The transport mirrors
 * the public {@see \App\Http\Controllers\Ai\ChatController} SSE contract
 * (reasoning/delta/tool/done/error) plus an `approval` or `question` event
 * per card whenever the turn paused, so the client renders تأكيد/رفض cards
 * inline. Decisions resume the SAME turn through {@see decide()} and the
 * continuation streams back over the identical SSE contract. Conversations
 * belong to the authenticated admin (AdminOwner, "admin:{id}").
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
 * turn entry (503, via TurnGuard) → the route's per-admin burst limiter (429).
 */
class AdminAssistantController extends Controller
{
    /** Usage feature label for admin assistant turns (ai-kit usage module). */
    private const FEATURE = 'admin_assistant';

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
        $prompt = $request->validated('message');

        return response()->stream(
            fn () => $this->streamTurn($prompt, $owner, $conversationId),
            200,
            SseStream::headers(),
        );
    }

    /**
     * POST /manage/assistant/chat/{conversation}/decide
     * (name: manage.assistant.decide) — resolve a paused turn's approval /
     * question cards and stream the continuation. The payload maps each
     * pending tool-call id to approve / reject / edit; every pending call
     * must be decided in one batch, because the vendor loop rejects any it
     * does not find a decision for.
     */
    public function decide(
        AdminAssistantDecisionRequest $request,
        AiSettings $settings,
        TurnGuard $guard,
        ConversationOwnership $ownership,
        AssistantCards $cards,
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

        // The 409 whose body is the CURRENT pending set, so a stale client
        // (double submit, another tab already decided) repaints instead of
        // guessing.
        $pendingIds = $cards->pendingFor($conversation)->pluck('id');

        if ($pendingIds->isEmpty()
            || $pendingIds->diff(array_keys($input))->isNotEmpty()
            || collect(array_keys($input))->diff($pendingIds)->isNotEmpty()) {
            return response()->json([
                'message' => 'هذه البطاقات لم تعد بانتظار قرار.',
                'pending_approvals' => $cards->pendingFor($conversation)->values(),
            ], 409);
        }

        try {
            $decisions = ResumeDecisions::fromClient($input);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'صيغة القرارات غير صالحة.'], 422);
        }

        return response()->stream(
            fn () => $this->streamTurn($decisions, $owner, $conversation),
            200,
            SseStream::headers(),
        );
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
     * Run the turn against the model and emit the SSE events, folded through
     * ai-kit's StreamEventMapper; a pause emits its approval/question cards
     * through {@see AssistantCards}. Every outcome — including a thrown
     * provider error — must land as an event.
     */
    private function streamTurn(
        Decisions|string $prompt,
        AdminOwner $owner,
        ?string $conversationId,
    ): void {
        $sse = new SseStream;
        $sse->extendTimeLimit((int) config('ai.chat.timeout', 60) + 30);

        // ai-kit's usage module records the turn (exact provider cost,
        // tokens, timings) automatically; the label is all it needs.
        Context::add(config('ai-kit.usage.feature_context_key'), self::FEATURE);

        $agent = AdminAssistant::make();
        $agent = $conversationId !== null
            ? $agent->continue($conversationId, $owner)
            : $agent->forUser($owner);

        try {
            $response = $agent->stream($prompt);

            app(AssistantCards::class)->attachTo(
                (new StreamEventMapper)
                    ->onError(fn (): string => $this->genericErrorMessage())
                    ->doneUsing(fn (): array => [
                        'conversation_id' => $response->conversationId ?? $conversationId,
                    ])
            )->run($response, fn (string $event, array $data) => $sse->emit($event, $data));
        } catch (Throwable $exception) {
            report($exception);

            $sse->emit('error', ['message' => $this->genericErrorMessage()]);
        }
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

    private function genericErrorMessage(): string
    {
        return 'حدث خطأ أثناء توليد الرد. حاول مرة أخرى.';
    }
}
