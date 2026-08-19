<?php

namespace App\Ai\Admin;

use App\Ai\Admin\Actions\AdminActionRegistry;
use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Saad\AiKit\Approvals\Classified\ApprovalCards;
use Saad\AiKit\Approvals\Classified\ResumeDecisions;
use Saad\AiKit\Approvals\Classified\StoredApprovals;
use Saad\AiKit\Streaming\StreamEventMapper;

/**
 * uqucc's view of ai-kit's approval cards: the kit resolves every
 * trust-bearing field server-side from the agent's own tools
 * ({@see ApprovalCards}); this presenter adds the app's `category`
 * grouping key so the client keeps its per-category icons and labels.
 *
 * Streaming pauses emit one `approval`/`question` SSE event per card via
 * {@see attachTo()}; a reloaded page repaints its undecided cards from the
 * stored pause markers via {@see pendingFor()}.
 *
 * The card's per-argument form schema (`fields`) comes from the kit, which
 * reads each tool's {@see \Saad\AiKit\Approvals\Classified\ClassifiedTool::fields()}
 * and infers the rest. Those flags live in the browser, so an edit decision
 * must come back through {@see editGuard()} — see {@see \App\Http\Controllers\Manage\AdminAssistantController::decide()}.
 */
class AssistantCards
{
    private readonly ApprovalCards $cards;

    public function __construct(private readonly AdminActionRegistry $registry, AdminAssistant $assistant)
    {
        $this->cards = new ApprovalCards($assistant->tools());
    }

    /**
     * @return array<string, mixed>
     */
    public function card(PendingApproval $approval): array
    {
        $card = $this->cards->card($approval);

        if ($card['kind'] === 'approval') {
            $card['category'] = $this->registry->get($approval->tool)?->category() ?? 'system';
        }

        return $card;
    }

    public function attachTo(StreamEventMapper $mapper): StreamEventMapper
    {
        return $mapper->on(
            ToolApprovalRequest::class,
            function (ToolApprovalRequest $event, callable $emit): void {
                foreach ($event->pendingApprovals as $approval) {
                    $card = $this->card($approval);

                    $emit($card['kind'] === 'question' ? 'question' : 'approval', $card);
                }
            },
        );
    }

    /**
     * The conversation's still-undecided pause markers — the SERVER's set,
     * which is what makes the original arguments trustworthy in
     * {@see editGuard()}. Query it once per request and pass the result to
     * both {@see cardsFor()} and {@see editGuard()}.
     *
     * @return Collection<int, PendingApproval>
     */
    public function pending(string $conversationId): Collection
    {
        return (new StoredApprovals)->pending($conversationId);
    }

    /**
     * @param  iterable<PendingApproval>  $pending
     * @return Collection<int, array<string, mixed>>
     */
    public function cardsFor(iterable $pending): Collection
    {
        return collect($pending)->map(fn (PendingApproval $approval): array => $this->card($approval));
    }

    /**
     * The conversation's still-undecided cards, for rehydration.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingFor(string $conversationId): Collection
    {
        return $this->cardsFor($this->pending($conversationId));
    }

    /**
     * The guard {@see ResumeDecisions::fromClient()} runs every edit through
     * before a decision exists: editable fields take the admin's values,
     * readonly and hidden ones are restored from the pending call, and an
     * edit that invents an argument key throws. Without it the card's
     * `editable` flags would be enforced nowhere but the browser, where a
     * retyped `page_id` repoints the write at a record no preview showed.
     *
     * @param  iterable<PendingApproval>  $pending  the set from {@see pending()}
     * @return Closure(string, array<string, mixed>): array<string, mixed>
     */
    public function editGuard(iterable $pending): Closure
    {
        return $this->cards->editGuard($pending);
    }
}
