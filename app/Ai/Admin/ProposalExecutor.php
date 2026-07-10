<?php

namespace App\Ai\Admin;

use App\Models\Ai\AdminPendingAction;
use App\Models\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase two of the admin assistant's write path: applies a PENDING proposal
 * after the admin pressed تأكيد. Every proposal is re-validated against the
 * current state first (the tree or settings may have changed since it was
 * proposed) and applied inside a transaction, with every page write going
 * through Eloquent model methods so the Page::booted() cache flushes fire
 * (frozen contract — never DB:: writes, never setNewOrder()).
 */
class ProposalExecutor
{
    public function __construct(
        private readonly PageChangeRules $rules,
        private readonly SettingsRegistry $registry,
    ) {}

    /**
     * Apply the proposal. On success the action becomes `confirmed`; any
     * failure (stale ids, type mismatch, database error) marks it `failed`
     * with the error surfaced for the action card.
     */
    public function confirm(AdminPendingAction $proposal): AdminPendingAction
    {
        if (! $proposal->isPending()) {
            return $proposal;
        }

        try {
            DB::transaction(function () use ($proposal): void {
                match ($proposal->type) {
                    AdminPendingAction::TYPE_PAGE_CHANGE => $this->applyPageChange($proposal->payload),
                    AdminPendingAction::TYPE_SETTINGS_CHANGE => $this->applySettingsChange($proposal->payload),
                    default => throw new InvalidProposalException('نوع الاقتراح غير معروف.'),
                };

                $proposal->update([
                    'status' => AdminPendingAction::STATUS_CONFIRMED,
                    'executed_at' => now(),
                    'error' => null,
                ]);
            });
        } catch (InvalidProposalException $exception) {
            $proposal->update([
                'status' => AdminPendingAction::STATUS_FAILED,
                'error' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $proposal->update([
                'status' => AdminPendingAction::STATUS_FAILED,
                'error' => 'حدث خطأ غير متوقع أثناء تنفيذ الاقتراح.',
            ]);
        }

        return $proposal->refresh();
    }

    /**
     * Decline the proposal; nothing is applied.
     */
    public function reject(AdminPendingAction $proposal): AdminPendingAction
    {
        if ($proposal->isPending()) {
            $proposal->update(['status' => AdminPendingAction::STATUS_REJECTED]);
        }

        return $proposal->refresh();
    }

    /**
     * Re-validate against the live tree, then apply through Eloquent.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyPageChange(array $payload): void
    {
        $payload = $this->rules->validate($payload);

        match ($payload['action']) {
            'create' => Page::create([
                'title' => $payload['title'],
                'slug' => $this->generateUniqueSlug($payload['title']),
                'parent_id' => $payload['parent_id'],
                'html_content' => '',
            ]),
            'rename' => $this->targetPage($payload)->update(['title' => $payload['title']]),
            'move' => $this->movePage($payload),
            'reorder' => $this->reorderPages($payload),
            'publish' => $this->targetPage($payload)->update(['hidden' => false]),
            'unpublish' => $this->targetPage($payload)->update(['hidden' => true]),
            'delete' => $this->deletePageWithDescendants($this->targetPage($payload)),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applySettingsChange(array $payload): void
    {
        $group = (string) ($payload['group'] ?? '');
        $key = (string) ($payload['key'] ?? '');
        $raw = (string) ($payload['raw_value'] ?? '');

        if (! array_key_exists($key, $this->registry->keysFor($group))) {
            throw new InvalidProposalException('الإعداد '.$group.'.'.$key.' لم يعد موجوداً.');
        }

        $casted = $this->registry->castValue($group, $key, $raw);

        if ($casted === null) {
            throw new InvalidProposalException('القيمة المقترحة لا تطابق نوع الإعداد.');
        }

        $this->registry->apply($group, $key, $casted['value']);
    }

    /**
     * Move to the new parent, landing at the end of the new siblings.
     *
     * @param  array<string, mixed>  $payload
     */
    private function movePage(array $payload): void
    {
        $page = $this->targetPage($payload);

        if ($page->parent_id === $payload['parent_id']) {
            return;
        }

        $lastOrder = (int) Page::query()
            ->where('parent_id', $payload['parent_id'])
            ->max('order');

        $page->update([
            'parent_id' => $payload['parent_id'],
            'order' => $lastOrder + 1,
        ]);
    }

    /**
     * Sequential sibling orders, saving each dirty model so events fire —
     * mirrors PageController::reorder(), never setNewOrder().
     *
     * @param  array<string, mixed>  $payload
     */
    private function reorderPages(array $payload): void
    {
        $pages = Page::query()->findMany($payload['ids'])->keyBy('id');

        foreach ($payload['ids'] as $index => $id) {
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
     * @param  array<string, mixed>  $payload
     */
    private function targetPage(array $payload): Page
    {
        $page = Page::query()->find((int) $payload['page_id']);

        if ($page === null) {
            throw new InvalidProposalException('الصفحة المستهدفة لم تعد موجودة.');
        }

        return $page;
    }

    /**
     * Slug parity with PageController::generateUniqueSlug() (and the
     * Telegram bot): Latin transliteration plus a numeric suffix on
     * collision; trashed pages count because the column is unique.
     */
    private function generateUniqueSlug(string $title): string
    {
        $baseSlug = '/'.Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        while (Page::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
