<?php

namespace App\Ai\Admin\Actions\Pages;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\Page;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Restore a soft-deleted (trashed) page. Mirrors
 * {@see \App\Http\Controllers\Manage\PageController::restore()}: a page cannot
 * be restored while its parent is still trashed (its place in the tree would
 * be undefined). Complements manage_page_structure's `delete`.
 */
class RestorePageAction extends AdminAction
{
    public function name(): string
    {
        return 'restore_page';
    }

    public function requiredAbility(): ?string
    {
        return 'edit-content';
    }

    public function category(): string
    {
        return 'pages';
    }

    public function description(): string
    {
        return 'Restore a trashed (soft-deleted) page (استعادة صفحة محذوفة). '
            .'Provide page_id (from list_pages — trashed pages show as «محذوفة»). '
            .'A page whose parent is still trashed cannot be restored until the parent is restored first.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        if ($user->mustHaveChangesReviewed()) {
            throw new AdminActionException('استعادة الصفحات لا تملك صلاحية تنفيذها مباشرة. You are not allowed to restore pages directly.');
        }

        $page = Page::onlyTrashed()->find((int) ($input['page_id'] ?? 0));

        if ($page === null) {
            throw new AdminActionException('لا توجد صفحة محذوفة بهذا المعرّف. استخدم list_pages للتأكد.');
        }

        if ($page->parent_id !== null && Page::onlyTrashed()->whereKey($page->parent_id)->exists()) {
            throw new AdminActionException('لا يمكن استعادة الصفحة قبل استعادة صفحتها الأب.');
        }

        return ['page_id' => $page->id, 'page_title' => $page->title];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'استعادة صفحة «'.$normalized['page_title'].'» من المحذوفات.';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $page = Page::onlyTrashed()->find((int) $normalized['page_id']);

        if ($page === null) {
            throw new AdminActionException('الصفحة لم تعد في المحذوفات.');
        }

        if ($page->parent_id !== null && Page::onlyTrashed()->whereKey($page->parent_id)->exists()) {
            throw new AdminActionException('لا يمكن استعادة الصفحة قبل استعادة صفحتها الأب.');
        }

        $page->restore();

        return ActionResult::text('تمت استعادة الصفحة «'.$page->title.'».');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()
                ->description('The id of the trashed page to restore, from list_pages.')
                ->required(),
        ];
    }
}
