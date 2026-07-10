<?php

namespace App\Ai\Admin\Tools;

use App\Ai\Admin\Tools\Concerns\GatedByAdminAssistant;
use App\Models\Page;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The operator's view of the full page tree — every page including hidden
 * ones, with the ids the propose_page_change tool needs. Admin-only:
 * NEVER registered in the public Toolbox.
 */
class ListPagesTool implements Tool
{
    use GatedByAdminAssistant;

    public function description(): Stringable|string
    {
        return 'List the full CMS page tree of the site (all pages including hidden ones) with each page\'s '
            .'id, title, slug, order, published state, and last update date '
            .'(عرض شجرة صفحات الموقع كاملة بمعرفاتها وترتيبها وحالة نشرها وتاريخ آخر تحديث). '
            .'Use the returned ids when proposing page changes. Read-only.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->adminAssistantIsDisabled()) {
            return $this->adminAssistantDisabledReply();
        }

        $pages = Page::query()->orderBy('order')->orderBy('id')->get();

        if ($pages->isEmpty()) {
            return 'لا توجد صفحات بعد.';
        }

        $childrenByParent = $pages->groupBy(fn (Page $page) => $page->parent_id ?? 0);

        return "شجرة صفحات الموقع (id | العنوان | slug | الترتيب | الحالة | آخر تحديث):\n"
            .implode("\n", $this->renderLevel($childrenByParent, 0, 0));
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
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
                $page->hidden ? 'مخفية' : 'منشورة',
                $page->updated_at?->toDateString() ?? '—',
            );

            $lines = [...$lines, ...$this->renderLevel($childrenByParent, $page->id, $depth + 1)];
        }

        return $lines;
    }
}
