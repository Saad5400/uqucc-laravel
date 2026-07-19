<?php

namespace App\Ai\Admin\Actions\Pages;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Ai\Admin\Actions\Concerns\InteractsWithPages;
use App\Ai\Admin\InvalidProposalException;
use App\Ai\Admin\PageChangeRules;
use App\Models\Page;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Structural change to the page tree: create, rename, move (to a new parent),
 * reorder siblings, publish/unpublish, or delete (cascading soft delete). The
 * validation and summary reuse {@see PageChangeRules} — the frozen source of
 * truth mirroring {@see \App\Http\Controllers\Manage\PageController} — and
 * every write goes through Eloquent so the Page::booted() cache flushes fire.
 */
class ManagePageStructureAction extends AdminAction
{
    use InteractsWithPages;

    public function __construct(private readonly PageChangeRules $rules) {}

    public function name(): string
    {
        return 'manage_page_structure';
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
        return 'Make ONE structural change to the site\'s page tree (تعديل بنية شجرة صفحات الموقع). '
            .'Actions: create (new page), rename, move (to a new parent), reorder (siblings of one parent), '
            .'publish, unpublish, delete (cascading soft delete). Use list_pages first for correct ids. '
            .'For editing a page\'s TEXT content use update_page_content; for its slug/icon/visibility flags use update_page.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        if ($user->mustHaveChangesReviewed()) {
            throw new AdminActionException('لا يمكن تمثيل تغييرات بنية الشجرة كتعديل قابل للمراجعة، ولا تملك صلاحية تنفيذها مباشرة. This structural change cannot be submitted for review.');
        }

        try {
            return $this->rules->validate($input);
        } catch (InvalidProposalException $exception) {
            throw new AdminActionException($exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return $this->rules->summarize($normalized);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        match ($normalized['action']) {
            'create' => Page::create([
                'title' => $normalized['title'],
                'slug' => $this->generateUniqueSlug($normalized['title']),
                'parent_id' => $normalized['parent_id'],
                'html_content' => '',
            ]),
            'rename' => $this->targetPage($normalized)->update(['title' => $normalized['title']]),
            'move' => $this->movePage($normalized),
            'reorder' => $this->reorderPages($normalized),
            'publish' => $this->targetPage($normalized)->update(['hidden' => false]),
            'unpublish' => $this->targetPage($normalized)->update(['hidden' => true]),
            'delete' => $this->deletePageWithDescendants($this->targetPage($normalized)),
        };

        return ActionResult::text('تم تنفيذ التغيير: '.$this->rules->summarize($normalized));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('The kind of structural change.')
                ->enum(PageChangeRules::ACTIONS)
                ->required(),
            'page_id' => $schema->integer()
                ->description('The target page id (from list_pages). Required for rename, move, publish, unpublish, delete.'),
            'title' => $schema->string()
                ->description('The page title: the new page\'s title for create, or the new title for rename.'),
            'parent_id' => $schema->integer()
                ->description('The parent page id. For create: where the new page goes (omit for root level). For move: the new parent (omit to move to root level).'),
            'ids' => $schema->array()
                ->description('For reorder only: ALL sibling page ids of one parent, in the desired new order.')
                ->items($schema->integer()),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function movePage(array $normalized): void
    {
        $page = $this->targetPage($normalized);

        if ($page->parent_id === $normalized['parent_id']) {
            return;
        }

        $lastOrder = (int) Page::query()
            ->where('parent_id', $normalized['parent_id'])
            ->max('order');

        $page->update([
            'parent_id' => $normalized['parent_id'],
            'order' => $lastOrder + 1,
        ]);
    }

    /**
     * Sequential sibling orders, saving each dirty model so events fire —
     * mirrors PageController::reorder(), never setNewOrder().
     *
     * @param  array<string, mixed>  $normalized
     */
    private function reorderPages(array $normalized): void
    {
        $pages = Page::query()->findMany($normalized['ids'])->keyBy('id');

        foreach ($normalized['ids'] as $index => $id) {
            $pages[$id]->update(['order' => $index + 1]);
        }
    }

    /**
     * Cascading soft delete over the subtree, one Eloquent delete per model —
     * mirrors PageController::destroy().
     */
    private function deletePageWithDescendants(Page $page): void
    {
        $childrenByParent = Page::query()
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        $descendantIds = [];
        $queue = [$page->id];

        while ($queue !== []) {
            $currentId = array_shift($queue);

            foreach ($childrenByParent->get($currentId, collect()) as $child) {
                $descendantIds[] = $child->id;
                $queue[] = $child->id;
            }
        }

        foreach (Page::query()->findMany($descendantIds) as $descendant) {
            $descendant->delete();
        }

        $page->delete();
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function targetPage(array $normalized): Page
    {
        $page = Page::query()->find((int) $normalized['page_id']);

        if ($page === null) {
            throw new AdminActionException('الصفحة المستهدفة لم تعد موجودة.');
        }

        return $page;
    }
}
