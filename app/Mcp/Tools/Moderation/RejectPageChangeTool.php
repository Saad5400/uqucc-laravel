<?php

namespace App\Mcp\Tools\Moderation;

use App\Models\PageChangeRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * Reject a pending page change: discard it without touching the live page.
 * Mirrors {@see \App\Http\Controllers\Manage\PageChangeRequestController::reject()}.
 */
#[Description('Reject a pending page edit — the live page is left untouched (رفض تعديل معلّق دون تغيير الصفحة). Takes the change request id from list_pending_changes and an optional note recorded on the request.')]
class RejectPageChangeTool extends ModerationTool
{
    protected string $name = 'reject_page_change';

    protected function requiredAbility(): string
    {
        return 'review-changes';
    }

    protected function perform(Request $request, User $user): Response
    {
        $changeRequest = PageChangeRequest::query()->find((int) $request->get('change_request_id'));

        if ($changeRequest === null) {
            return Response::error('لم يُعثر على التعديل المطلوب. No change request found for that id.');
        }

        if (! $changeRequest->isPending()) {
            return Response::error('هذا التعديل لم يعد بانتظار المراجعة. This change request is no longer pending.');
        }

        $changeRequest->update([
            'status' => PageChangeRequest::STATUS_REJECTED,
            'reviewed_by' => $user->getKey(),
            'reviewed_at' => now(),
            'review_note' => $request->get('note'),
        ]);

        return Response::text('تم رفض التعديل — لم تتغيّر الصفحة. The change was rejected; the page is unchanged.');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'change_request_id' => $schema->integer()
                ->description('The id of the pending change request to reject, from list_pending_changes.')
                ->required(),
            'note' => $schema->string()
                ->description('Optional note explaining the rejection, recorded on the request.'),
        ];
    }
}
