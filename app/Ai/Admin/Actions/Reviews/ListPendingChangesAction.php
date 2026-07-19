<?php

namespace App\Ai\Admin\Actions\Reviews;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\PageChangeRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The review queue: page edits review-mode editors submitted and that are still
 * waiting on a decision. Each entry carries the change request id, its target
 * page, the author, the fields it changes and when it was submitted — pass the
 * id to show_page_change, approve_page_change or reject_page_change. Read-only.
 * Unifies the MCP `list_pending_changes` tool into one action on both surfaces.
 */
class ListPendingChangesAction extends AdminAction
{
    public function name(): string
    {
        return 'list_pending_changes';
    }

    public function requiredAbility(): ?string
    {
        return 'review-changes';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'reviews';
    }

    public function description(): string
    {
        return 'List the page edits awaiting review (التعديلات المعلّقة بانتظار المراجعة). '
            .'Each entry has the change request id, the target page, the author, the fields it changes, '
            .'and when it was submitted — pass the id to show_page_change to see the diff, then to '
            .'approve_page_change or reject_page_change. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $pending = PageChangeRequest::query()
            ->where('status', PageChangeRequest::STATUS_PENDING)
            ->with(['page', 'author'])
            ->latest('updated_at')
            ->get()
            ->map(fn (PageChangeRequest $change): array => [
                'id' => $change->id,
                'page_title' => $change->page?->title,
                'page_trashed' => (bool) $change->page?->trashed(),
                'author' => $change->author?->name,
                'changed_fields' => array_keys($change->payload),
                'submitted_at' => $change->created_at?->toDateTimeString(),
            ])
            ->values();

        if ($pending->isEmpty()) {
            return ActionResult::text('لا توجد تعديلات بانتظار المراجعة. No page edits are awaiting review.');
        }

        return ActionResult::text((string) json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
