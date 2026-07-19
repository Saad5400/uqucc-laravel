<?php

namespace App\Ai\Admin\Actions\Reviews;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\PageChangeRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Approve a pending page change: replay its captured payload against the live
 * page through Eloquent (so the `Page::booted()` cache flushes fire), then mark
 * the request approved with the acting reviewer. Re-application can still fail
 * if the page changed since submission (e.g. a slug was taken); the request
 * then stays pending. Unifies the MCP `approve_page_change` tool. Mirrors
 * {@see \App\Http\Controllers\Manage\PageChangeRequestController::approve()}.
 */
class ApprovePageChangeAction extends AdminAction
{
    public function name(): string
    {
        return 'approve_page_change';
    }

    public function requiredAbility(): ?string
    {
        return 'review-changes';
    }

    public function category(): string
    {
        return 'reviews';
    }

    public function description(): string
    {
        return 'Approve a pending page edit and publish it to the live page (اعتماد تعديل معلّق ونشره على الصفحة). '
            .'Provide change_request_id from list_pending_changes — inspect it with show_page_change first. '
            .'Re-application can still fail if the page changed since submission (e.g. a slug was taken); '
            .'the request then stays pending.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $changeRequest = PageChangeRequest::query()->find((int) ($input['change_request_id'] ?? 0));

        if ($changeRequest === null) {
            throw new AdminActionException('لم يُعثر على التعديل المطلوب. استخدم list_pending_changes للتأكد من المعرّف.');
        }

        if (! $changeRequest->isPending()) {
            throw new AdminActionException('هذا التعديل لم يعد بانتظار المراجعة.');
        }

        if ($changeRequest->page === null) {
            throw new AdminActionException('الصفحة المستهدفة لم تعد موجودة — لا يمكن اعتماد التعديل.');
        }

        return [
            'change_request_id' => $changeRequest->id,
            'page_title' => $changeRequest->page->title,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'اعتماد تعديل صفحة «'.$normalized['page_title'].'» ونشره على الصفحة الفعلية.';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $changeRequest = PageChangeRequest::query()->find((int) $normalized['change_request_id']);

        if ($changeRequest === null) {
            throw new AdminActionException('التعديل المطلوب لم يعد موجوداً.');
        }

        if (! $changeRequest->isPending()) {
            throw new AdminActionException('هذا التعديل لم يعد بانتظار المراجعة.');
        }

        if ($changeRequest->page === null) {
            throw new AdminActionException('الصفحة المستهدفة لم تعد موجودة — لا يمكن اعتماد التعديل.');
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
        } catch (Throwable $exception) {
            report($exception);

            throw new AdminActionException('تعذّر اعتماد التعديل. ربما تغيّرت الصفحة منذ إرساله.');
        }

        return ActionResult::text('تم اعتماد التعديل ونشره على الصفحة «'.$changeRequest->page->title.'».');
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
