<?php

namespace App\Mcp\Tools\Moderation;

use App\Models\Page;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * Soft-delete a page (move it to the trash) or restore a trashed one. Both go
 * through Eloquent so the `Page::booted()` cache flushes fire.
 *
 * Trashing is destructive and cannot be represented as a reviewable change
 * request, so — unlike {@see UpdatePageTool} — it is refused for review-mode
 * editors: their account funnels edits through the review queue, and a live
 * delete would bypass it. They should ask a reviewer or admin.
 */
#[Description('Move a guide page to the trash, or restore a trashed page (نقل صفحة إلى المهملات أو استعادتها). Requires page_id (from list_managed_pages); set restore=true to restore. Soft delete only — content is recoverable. Not available to review-mode accounts.')]
class TrashPageTool extends ModerationTool
{
    protected string $name = 'trash_page';

    protected function requiredAbility(): string
    {
        return 'edit-content';
    }

    protected function perform(Request $request, User $user): Response
    {
        if ($user->mustHaveChangesReviewed()) {
            return Response::error('حسابك في وضع المراجعة، ولا يمكنه حذف الصفحات أو استعادتها مباشرةً. اطلب ذلك من مراجع أو مدير. Your account is in review mode and cannot trash or restore pages directly.');
        }

        $restore = filter_var($request->get('restore'), FILTER_VALIDATE_BOOL);

        $page = Page::withTrashed()->find((int) $request->get('page_id'));

        if ($page === null) {
            return Response::error('لم يُعثر على الصفحة المطلوبة. No page found for that id.');
        }

        if ($restore) {
            if (! $page->trashed()) {
                return Response::error('الصفحة «'.$page->title.'» ليست في المهملات. That page is not in the trash.');
            }

            $page->restore();

            return Response::text('تمت استعادة الصفحة «'.$page->title.'». Page restored.');
        }

        if ($page->trashed()) {
            return Response::error('الصفحة «'.$page->title.'» موجودة في المهملات بالفعل. That page is already in the trash.');
        }

        $page->delete();

        return Response::text('تم نقل الصفحة «'.$page->title.'» إلى المهملات. Page moved to the trash.');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()
                ->description('The id of the page to trash or restore, from list_managed_pages.')
                ->required(),
            'restore' => $schema->boolean()
                ->description('Set to true to restore a trashed page instead of trashing it. Defaults to false.'),
        ];
    }
}
