<?php

namespace App\Ai\Admin\Actions\Reviews;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\PageChangeRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Reject a pending page change: discard it without touching the live page, and
 * record the acting reviewer with an optional note. Unifies the MCP
 * `reject_page_change` tool. Mirrors
 * {@see \App\Http\Controllers\Manage\PageChangeRequestController::reject()}.
 */
class RejectPageChangeAction extends AdminAction
{
    public function name(): string
    {
        return 'reject_page_change';
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
        return 'Reject a pending page edit — the live page is left untouched (رفض تعديل معلّق دون تغيير الصفحة). '
            .'Provide change_request_id from list_pending_changes and an optional note recorded on the request.';
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

        $note = trim((string) ($input['note'] ?? ''));

        return [
            'change_request_id' => $changeRequest->id,
            'page_title' => $changeRequest->page?->title,
            'note' => $note === '' ? null : $note,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        $target = $normalized['page_title'] !== null ? 'صفحة «'.$normalized['page_title'].'»' : 'الصفحة المستهدفة';

        return 'رفض تعديل '.$target.' دون تغيير الصفحة الفعلية.';
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

        $changeRequest->update([
            'status' => PageChangeRequest::STATUS_REJECTED,
            'reviewed_by' => $user->getKey(),
            'reviewed_at' => now(),
            'review_note' => $normalized['note'],
        ]);

        return ActionResult::text('تم رفض التعديل — لم تتغيّر الصفحة.');
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
