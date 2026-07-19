<?php

namespace App\Ai\Admin\Actions\Pages;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\Page;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;

/**
 * The operator's view of the full page tree — every page including hidden and
 * trashed ones, with the ids the page-editing actions need and each page's
 * visibility flags. Read-only. Unifies the old admin `list_pages` and the MCP
 * `list_managed_pages` into one action on both surfaces.
 */
class ListPagesAction extends AdminAction
{
    public function name(): string
    {
        return 'list_pages';
    }

    public function requiredAbility(): ?string
    {
        return 'edit-content';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'pages';
    }

    public function description(): string
    {
        return 'List the full CMS page tree (all pages including hidden and trashed) with each page\'s id, title, '
            .'slug, order, visibility flags and last update date '
            .'(عرض شجرة صفحات الموقع كاملة بمعرفاتها وترتيبها وحالة نشرها وإخفائها وتاريخ آخر تحديث). '
            .'Use the returned ids for the page-editing actions. Read-only.';
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
        $pages = Page::withTrashed()->orderBy('order')->orderBy('id')->get();

        if ($pages->isEmpty()) {
            return ActionResult::text('لا توجد صفحات بعد.');
        }

        $childrenByParent = $pages->groupBy(fn (Page $page) => $page->parent_id ?? 0);

        return ActionResult::text(
            "شجرة صفحات الموقع (id | العنوان | slug | الترتيب | الحالة | آخر تحديث):\n"
            .implode("\n", $this->renderLevel($childrenByParent, 0, 0)),
        );
    }

    /**
     * @param  Collection<int|string, Collection<int, Page>>  $childrenByParent
     * @return list<string>
     */
    private function renderLevel(Collection $childrenByParent, int $parentId, int $depth): array
    {
        $lines = [];

        foreach ($childrenByParent->get($parentId, collect()) as $page) {
            $lines[] = str_repeat('  ', $depth).sprintf(
                '- [%d] %s | %s | order=%d | %s | %s',
                $page->id,
                $page->title,
                $page->slug,
                $page->order,
                $this->stateLabel($page),
                $page->updated_at?->toDateString() ?? '—',
            );

            $lines = [...$lines, ...$this->renderLevel($childrenByParent, $page->id, $depth + 1)];
        }

        return $lines;
    }

    private function stateLabel(Page $page): string
    {
        if ($page->trashed()) {
            return 'محذوفة';
        }

        $flags = [$page->hidden ? 'مخفية' : 'منشورة'];

        if ($page->hidden_from_bot) {
            $flags[] = 'مخفية عن البوت';
        }

        if ($page->hidden_from_ai) {
            $flags[] = 'مخفية عن الذكاء';
        }

        return implode('، ', $flags);
    }
}
