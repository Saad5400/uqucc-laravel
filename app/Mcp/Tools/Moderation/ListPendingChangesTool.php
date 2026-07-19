<?php

namespace App\Mcp\Tools\Moderation;

use App\Models\PageChangeRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The review queue: page edits review-mode editors submitted and that are
 * still waiting on a decision. Read-only companion to
 * {@see ApprovePageChangeTool} / {@see RejectPageChangeTool}.
 */
#[IsReadOnly]
#[Description('List the page edits awaiting review (التعديلات المعلّقة بانتظار المراجعة). Each entry has the change request id, the target page, the author, the fields it changes, and when it was submitted — pass the id to approve_page_change or reject_page_change.')]
class ListPendingChangesTool extends ModerationTool
{
    protected string $name = 'list_pending_changes';

    protected function requiredAbility(): string
    {
        return 'review-changes';
    }

    protected function perform(Request $request, User $user): Response
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
            return Response::text('لا توجد تعديلات بانتظار المراجعة. No page edits are awaiting review.');
        }

        return Response::text((string) json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
