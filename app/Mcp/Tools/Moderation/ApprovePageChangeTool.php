<?php

namespace App\Mcp\Tools\Moderation;

use App\Models\PageChangeRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * Approve a pending page change: replay its captured payload against the live
 * page through Eloquent (so the `Page::booted()` cache flushes fire), then
 * mark the request approved. Mirrors
 * {@see \App\Http\Controllers\Manage\PageChangeRequestController::approve()}.
 */
#[Description('Approve a pending page edit and publish it to the live page (اعتماد تعديل معلّق ونشره). Takes the change request id from list_pending_changes. Re-application can still fail if the page changed since submission (e.g. a slug was taken); the request then stays pending.')]
class ApprovePageChangeTool extends ModerationTool
{
    protected string $name = 'approve_page_change';

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

        if ($changeRequest->page === null) {
            return Response::error('الصفحة المستهدفة لم تعد موجودة — لا يمكن اعتماد التعديل. The target page no longer exists.');
        }

        try {
            DB::transaction(function () use ($changeRequest, $user): void {
                $changeRequest->page->update($changeRequest->payload);

                $changeRequest->update([
                    'status' => PageChangeRequest::STATUS_APPROVED,
                    'reviewed_by' => $user->getKey(),
                    'reviewed_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            report($exception);

            return Response::error('تعذّر اعتماد التعديل. ربما تغيّرت الصفحة منذ إرساله. Could not apply the change; the page may have changed since submission.');
        }

        return Response::text('تم اعتماد التعديل ونشره على الصفحة «'.$changeRequest->page->title.'». The change was approved and published.');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'change_request_id' => $schema->integer()
                ->description('The id of the pending change request to approve, from list_pending_changes.')
                ->required(),
        ];
    }
}
