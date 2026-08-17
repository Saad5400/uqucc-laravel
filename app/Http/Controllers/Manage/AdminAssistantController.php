<?php

namespace App\Http\Controllers\Manage;

use App\Ai\Admin\AdminAssistant;
use App\Ai\Admin\AdminOwner;
use App\Ai\Admin\ProposalExecutor;
use App\Ai\Admin\ProposalExtractor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\AdminAssistantMessageRequest;
use App\Models\Ai\AdminPendingAction;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Saad\AiKit\Conversations\ConversationOwnership;
use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Streaming\SseStream;
use Saad\AiKit\Streaming\StreamEventMapper;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The /manage admin assistant chat API — the operator copilot with
 * confirm-gated write powers. The transport mirrors the public
 * {@see \App\Http\Controllers\Ai\ChatController} SSE contract
 * (delta/done/error) plus a `proposal` event whenever the model persisted a
 * pending action, so the client renders تأكيد/رفض cards inline. Conversations
 * belong to the authenticated admin (AdminOwner, "admin:{id}").
 *
 * Layered gates on every endpoint: panel auth (route middleware) → master
 * ai_enabled AND admin_assistant_enabled (503 with the reason) → daily spend
 * budget (503) → the route's per-admin burst limiter (429).
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
    public function index(AiSettings $settings): Response
    {
        return Inertia::render('manage/assistant/Index', [
            'assistant' => [
                'enabled' => $settings->isFeatureEnabled('admin_assistant'),
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
        BudgetGuard $budget,
        ConversationOwnership $ownership,
        ProposalExtractor $proposals,
    ): JsonResponse|StreamedResponse {
        if (! $settings->isFeatureEnabled('admin_assistant')) {
            return $this->disabledResponse($settings);
        }

        if ($budget->exceeded()) {
            return response()->json(['message' => __('ai-kit::safety.budget_exceeded')], 503);
        }

        /** @var User $admin */
        $admin = $request->user();

        $owner = new AdminOwner($admin);
        $conversationId = $this->ownedConversationId($request->input('conversation_id'), $owner, $ownership);
        $prompt = $request->validated('message');

        return response()->stream(
            fn () => $this->streamTurn($prompt, $owner, $conversationId, $proposals),
            200,
            SseStream::headers(),
        );
    }

    /**
     * GET /manage/assistant/chat/{conversation} (name: manage.assistant.show)
     * — the stored thread for rehydrating the panel, action cards included
     * with their CURRENT status. 404 unless the admin owns the thread.
     */
    public function show(
        Request $request,
        AiSettings $settings,
        ConversationOwnership $ownership,
        ProposalExtractor $proposals,
        string $conversation,
    ): JsonResponse {
        if (! $settings->isFeatureEnabled('admin_assistant')) {
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
                'content' => (string) $message->getAttribute('content'),
                'proposals' => $message->getAttribute('role') === 'assistant'
                    ? $proposals->extractFromStored((array) $message->getAttribute('tool_results'))
                    : [],
                'created_at' => $message->getAttribute('created_at')?->toIso8601String(),
            ])
            ->values();

        return response()->json(['messages' => $messages]);
    }

    /**
     * POST /manage/assistant/proposals/{proposal}/confirm
     * (name: manage.assistant.proposals.confirm) — apply a pending proposal.
     */
    public function confirm(
        AiSettings $settings,
        ProposalExecutor $executor,
        AdminPendingAction $proposal,
    ): JsonResponse {
        if (! $settings->isFeatureEnabled('admin_assistant')) {
            return $this->disabledResponse($settings);
        }

        if (! $proposal->isPending()) {
            return response()->json([
                'message' => 'هذا الاقتراح لم يعد بانتظار التأكيد.',
                'proposal' => $proposal->toClientPayload(),
            ], 409);
        }

        return response()->json(['proposal' => $executor->confirm($proposal)->toClientPayload()]);
    }

    /**
     * POST /manage/assistant/proposals/{proposal}/reject
     * (name: manage.assistant.proposals.reject) — decline a pending proposal.
     */
    public function reject(
        AiSettings $settings,
        ProposalExecutor $executor,
        AdminPendingAction $proposal,
    ): JsonResponse {
        if (! $settings->isFeatureEnabled('admin_assistant')) {
            return $this->disabledResponse($settings);
        }

        if (! $proposal->isPending()) {
            return response()->json([
                'message' => 'هذا الاقتراح لم يعد بانتظار التأكيد.',
                'proposal' => $proposal->toClientPayload(),
            ], 409);
        }

        return response()->json(['proposal' => $executor->reject($proposal)->toClientPayload()]);
    }

    /**
     * Run the turn against the model and emit the SSE events, folded through
     * ai-kit's StreamEventMapper (proposal cards ride a ToolResult hook).
     * Every outcome — including a thrown provider error — must land as an
     * event.
     */
    private function streamTurn(
        string $prompt,
        AdminOwner $owner,
        ?string $conversationId,
        ProposalExtractor $proposals,
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

            (new StreamEventMapper)
                ->onError(fn (): string => $this->genericErrorMessage())
                ->on(ToolResultEvent::class, function (ToolResultEvent $event, callable $emit) use ($proposals): void {
                    foreach ($proposals->extract([$event->toolResult]) as $card) {
                        $emit('proposal', $card);
                    }
                })
                ->doneUsing(fn (): array => [
                    'conversation_id' => $response->conversationId ?? $conversationId,
                ])
                ->run($response, fn (string $event, array $data) => $sse->emit($event, $data));
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
