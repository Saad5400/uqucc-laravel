<?php

namespace App\Ai\Admin;

use App\Models\Page;

/**
 * The single source of truth for what a page-change proposal may do. Both
 * phases run through it: the propose_page_change tool validates before
 * persisting a pending action, and {@see ProposalExecutor} re-validates at
 * confirm time (the tree may have changed between proposal and تأكيد).
 *
 * Semantics mirror {@see \App\Http\Controllers\Manage\PageController}:
 * publish/unpublish maps to the `hidden` flag, reorder only rearranges
 * siblings of one parent, move blocks cycles, delete cascades over
 * descendants — and every write later happens through Eloquent so the
 * Page::booted() cache flushes keep firing (frozen contract).
 */
class PageChangeRules
{
    public const ACTIONS = ['create', 'rename', 'move', 'reorder', 'publish', 'unpublish', 'delete'];

    /**
     * Validate and normalize a raw payload. Returns the normalized payload
     * (ints cast, titles trimmed, display names resolved) or throws an
     * {@see InvalidProposalException} with an Arabic reason.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        $action = is_string($payload['action'] ?? null) ? $payload['action'] : '';

        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidProposalException('الإجراء غير معروف. الإجراءات المتاحة: '.implode('، ', self::ACTIONS).'.');
        }

        return match ($action) {
            'create' => $this->validateCreate($payload),
            'rename' => $this->validateRename($payload),
            'move' => $this->validateMove($payload),
            'reorder' => $this->validateReorder($payload),
            'publish', 'unpublish' => $this->validateVisibility($action, $payload),
            'delete' => $this->validateDelete($payload),
        };
    }

    /**
     * The Arabic human summary of a normalized payload — what the admin reads
     * on the action card before pressing تأكيد.
     *
     * @param  array<string, mixed>  $payload
     */
    public function summarize(array $payload): string
    {
        return match ($payload['action']) {
            'create' => $payload['parent_id'] === null
                ? "إنشاء صفحة جديدة بعنوان «{$payload['title']}» في المستوى الرئيسي."
                : "إنشاء صفحة جديدة بعنوان «{$payload['title']}» داخل صفحة «{$payload['parent_title']}».",
            'rename' => "إعادة تسمية صفحة «{$payload['page_title']}» إلى «{$payload['title']}».",
            'move' => $payload['parent_id'] === null
                ? "نقل صفحة «{$payload['page_title']}» إلى المستوى الرئيسي."
                : "نقل صفحة «{$payload['page_title']}» لتصبح داخل صفحة «{$payload['parent_title']}».",
            'reorder' => 'إعادة ترتيب '.count($payload['ids']).' صفحات'
                .($payload['parent_id'] === null ? ' في المستوى الرئيسي.' : " داخل صفحة «{$payload['parent_title']}»."),
            'publish' => "نشر (إظهار) صفحة «{$payload['page_title']}».",
            'unpublish' => "إخفاء صفحة «{$payload['page_title']}» من الموقع.",
            'delete' => "حذف صفحة «{$payload['page_title']}» (حذف مؤقت قابل للاستعادة، ويشمل صفحاتها الفرعية).",
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateCreate(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));

        if ($title === '') {
            throw new InvalidProposalException('عنوان الصفحة الجديدة مطلوب.');
        }

        $parent = $this->optionalParent($payload);

        return [
            'action' => 'create',
            'title' => $title,
            'parent_id' => $parent?->id,
            'parent_title' => $parent?->title,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateRename(array $payload): array
    {
        $page = $this->requiredPage($payload);
        $title = trim((string) ($payload['title'] ?? ''));

        if ($title === '') {
            throw new InvalidProposalException('العنوان الجديد مطلوب.');
        }

        return [
            'action' => 'rename',
            'page_id' => $page->id,
            'page_title' => $page->title,
            'title' => $title,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateMove(array $payload): array
    {
        $page = $this->requiredPage($payload);
        $parent = $this->optionalParent($payload);

        if ($parent !== null) {
            if ($parent->id === $page->id) {
                throw new InvalidProposalException('لا يمكن نقل الصفحة داخل نفسها.');
            }

            if (in_array($parent->id, $this->descendantIds($page->id), true)) {
                throw new InvalidProposalException('لا يمكن نقل الصفحة داخل إحدى صفحاتها الفرعية.');
            }
        }

        return [
            'action' => 'move',
            'page_id' => $page->id,
            'page_title' => $page->title,
            'parent_id' => $parent?->id,
            'parent_title' => $parent?->title,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateReorder(array $payload): array
    {
        $rawIds = $payload['ids'] ?? null;

        if (! is_array($rawIds) || $rawIds === []) {
            throw new InvalidProposalException('قائمة معرفات الصفحات بالترتيب الجديد مطلوبة.');
        }

        $ids = array_values(array_map(intval(...), $rawIds));

        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidProposalException('قائمة الترتيب تحتوي على معرّف مكرر.');
        }

        $pages = Page::query()->findMany($ids);

        if ($pages->count() !== count($ids)) {
            throw new InvalidProposalException('إحدى الصفحات في قائمة الترتيب غير موجودة.');
        }

        $parentIds = $pages->pluck('parent_id')->unique();

        if ($parentIds->count() > 1) {
            throw new InvalidProposalException('كل الصفحات في قائمة الترتيب يجب أن تنتمي إلى نفس الصفحة الأب.');
        }

        $parentId = $parentIds->first();
        $parent = $parentId === null ? null : Page::query()->find($parentId);
        $pagesById = $pages->keyBy('id');

        return [
            'action' => 'reorder',
            'parent_id' => $parentId,
            'parent_title' => $parent?->title,
            'ids' => $ids,
            'titles' => array_map(fn (int $id): string => $pagesById[$id]->title, $ids),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateVisibility(string $action, array $payload): array
    {
        $page = $this->requiredPage($payload);

        return [
            'action' => $action,
            'page_id' => $page->id,
            'page_title' => $page->title,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateDelete(array $payload): array
    {
        $page = $this->requiredPage($payload);

        return [
            'action' => 'delete',
            'page_id' => $page->id,
            'page_title' => $page->title,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredPage(array $payload): Page
    {
        $pageId = $payload['page_id'] ?? null;

        if (! is_numeric($pageId)) {
            throw new InvalidProposalException('معرّف الصفحة (page_id) مطلوب — استخدم أداة list_pages للحصول على المعرفات.');
        }

        $page = Page::query()->find((int) $pageId);

        if ($page === null) {
            throw new InvalidProposalException('لا توجد صفحة بالمعرّف '.(int) $pageId.'. استخدم أداة list_pages للتأكد من المعرفات.');
        }

        return $page;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optionalParent(array $payload): ?Page
    {
        $parentId = $payload['parent_id'] ?? null;

        if ($parentId === null || $parentId === '' || $parentId === 0 || $parentId === '0') {
            return null;
        }

        if (! is_numeric($parentId)) {
            throw new InvalidProposalException('معرّف الصفحة الأب (parent_id) غير صالح.');
        }

        $parent = Page::query()->find((int) $parentId);

        if ($parent === null) {
            throw new InvalidProposalException('لا توجد صفحة أب بالمعرّف '.(int) $parentId.'.');
        }

        return $parent;
    }

    /**
     * Ids of every live descendant of the given page.
     *
     * @return list<int>
     */
    private function descendantIds(int $pageId): array
    {
        $childrenByParent = Page::query()
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        $ids = [];
        $queue = [$pageId];

        while ($queue !== []) {
            $currentId = array_shift($queue);

            foreach ($childrenByParent->get($currentId, collect()) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }
}
