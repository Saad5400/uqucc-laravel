<?php

namespace App\Ai\Admin;

use App\Ai\Admin\Actions\AdminActionRegistry;
use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Saad\AiKit\Approvals\Classified\ApprovalCards;
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
     * The conversation's still-undecided cards, for rehydration.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingFor(string $conversationId): Collection
    {
        return (new StoredApprovals)->pending($conversationId)
            ->map(fn (PendingApproval $approval): array => $this->card($approval));
    }
}
