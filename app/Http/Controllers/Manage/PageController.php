<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\ReorderPagesRequest;
use App\Http\Requests\Manage\StorePageRequest;
use App\Http\Requests\Manage\UpdatePageRequest;
use App\Models\Page;
use App\Models\User;
use App\Settings\AiSettings;
use App\Support\Disk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    /**
     * Show the pages tree. The whole tree is shared eagerly (the site is
     * ~107 pages); trashed pages are deferred since the trash is a rare path.
     */
    public function index(): Response
    {
        $pages = Page::query()->orderBy('order')->orderBy('id')->get();
        $childrenByParent = $pages->groupBy(fn (Page $page) => $page->parent_id ?? 0);

        $buildTree = function (int $parentId) use (&$buildTree, $childrenByParent): array {
            return $childrenByParent->get($parentId, collect())
                ->map(fn (Page $page) => [
                    'id' => $page->id,
                    'title' => $page->title,
                    'slug' => $page->slug,
                    'icon' => $page->icon,
                    'hidden' => $page->hidden,
                    'hidden_from_bot' => $page->hidden_from_bot,
                    'smart_search' => $page->smart_search,
                    'has_content' => $this->hasContent($page),
                    'order' => $page->order,
                    'children_count' => $childrenByParent->get($page->id, collect())->count(),
                    'children' => $buildTree($page->id),
                ])
                ->values()
                ->all();
        };

        return Inertia::render('manage/pages/Index', [
            'pages' => $buildTree(0),
            'trashedPages' => Inertia::defer(fn () => Page::onlyTrashed()
                ->with(['parent' => fn ($query) => $query->withTrashed()])
                ->withCount(['children as children_count' => fn ($query) => $query->withTrashed()])
                ->orderByDesc('deleted_at')
                ->get()
                ->map(fn (Page $page) => [
                    'id' => $page->id,
                    'title' => $page->title,
                    'slug' => $page->slug,
                    'deleted_at' => $page->deleted_at->toDateTimeString(),
                    'parent_title' => $page->parent?->title,
                    'children_count' => $page->children_count,
                ])
                ->values()),
        ]);
    }

    /**
     * Create a page from a title (+ optional parent) and open its workspace.
     * The slug is generated exactly like the original admin form prefilled it:
     * `'/'.str($title)->slug()` (Latin transliteration); on collision a
     * numeric suffix is appended, same as the Telegram bot's page creation.
     */
    public function store(StorePageRequest $request): RedirectResponse
    {
        $page = Page::create([
            'title' => $request->validated('title'),
            'slug' => $this->generateUniqueSlug($request->validated('title')),
            'parent_id' => $request->validated('parent_id'),
            'html_content' => '',
        ]);

        return to_route('manage.pages.edit', $page)->with('success', 'تم إنشاء الصفحة.');
    }

    /**
     * Show the page workspace. The route binding includes trashed pages
     * (mirroring the original admin panel, which stripped the soft-delete scope) so a trashed
     * page can still be opened and restored from its workspace.
     */
    public function edit(Page $page): Response
    {
        $page->load(['children', 'users']);

        $allPages = Page::withTrashed()->orderBy('order')->orderBy('id')->get(['id', 'title', 'parent_id', 'order', 'deleted_at']);
        $pagesById = $allPages->keyBy('id');
        $livePagesByParent = $allPages->filter(fn (Page $candidate) => ! $candidate->trashed())
            ->groupBy(fn (Page $candidate) => $candidate->parent_id ?? 0);

        return Inertia::render('manage/pages/Edit', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'icon' => $page->icon,
                'hidden' => $page->hidden,
                'hidden_from_bot' => $page->hidden_from_bot,
                'hidden_from_ai' => $page->hidden_from_ai,
                'smart_search' => $page->smart_search,
                'requires_prefix' => $page->requires_prefix,
                'parent_id' => $page->parent_id,
                'order' => $page->order,
                'html_content' => $page->html_content,
                'quick_response_auto_extract_message' => $page->quick_response_auto_extract_message,
                'quick_response_auto_extract_buttons' => $page->quick_response_auto_extract_buttons,
                'quick_response_auto_extract_attachments' => $page->quick_response_auto_extract_attachments,
                'quick_response_send_link' => $page->quick_response_send_link,
                'quick_response_send_screenshot' => $page->quick_response_send_screenshot,
                'quick_response_message' => $page->quick_response_message,
                'quick_response_buttons' => $page->quick_response_buttons ?? [],
                'quick_response_attachments' => $page->quick_response_attachments ?? [],
                'deleted_at' => $page->deleted_at?->toDateTimeString(),
            ],
            'parentChain' => $this->parentChain($page, $pagesById),
            'children' => $page->children->map(fn (Page $child) => [
                'id' => $child->id,
                'title' => $child->title,
                'slug' => $child->slug,
                'hidden' => $child->hidden,
                'children_count' => $livePagesByParent->get($child->id, collect())->count(),
            ])->values(),
            'authors' => $page->users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
            ])->values(),
            'parentOptions' => $this->flattenTree($livePagesByParent),
            'descendantIds' => $this->descendantIds($page->id, $allPages->groupBy('parent_id')),
            'users' => User::query()->orderBy('name')->get(['id', 'name'])->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
            ]),
            'attachments' => collect($page->quick_response_attachments ?? [])->map(fn (string $path) => [
                'path' => $path,
                'url' => Storage::disk(Disk::MEDIA)->url($path),
                'name' => basename($path),
            ])->values(),
            'copilot' => [
                'enabled' => app(AiSettings::class)->isFeatureEnabled('admin_copilot'),
            ],
        ]);
    }

    /**
     * Apply a partial update. All writes go through Eloquent so the
     * `Page::booted()` cache flushes keep firing (frozen contract).
     */
    public function update(UpdatePageRequest $request, Page $page): RedirectResponse
    {
        $data = $request->validated();

        if (array_key_exists('html_content', $data) && $data['html_content'] === null) {
            $data['html_content'] = '';
        }

        $page->update($data);

        return back()->with('success', 'تم حفظ التغييرات.');
    }

    /**
     * Soft delete the page together with its whole subtree, so no child is
     * left orphaned under a trashed parent. Each model is deleted through
     * Eloquent to keep the cache-flush events firing.
     */
    public function destroy(Page $page): RedirectResponse
    {
        $descendantIds = $this->descendantIds($page->id, Page::query()->whereNotNull('parent_id')->get(['id', 'parent_id'])->groupBy('parent_id'));

        foreach (Page::query()->findMany($descendantIds) as $descendant) {
            $descendant->delete();
        }

        $page->delete();

        return back()->with('success', 'تم حذف الصفحة. يمكن استعادتها من قسم «المحذوفة».');
    }

    /**
     * Restore a trashed page together with its trashed descendants
     * (the mirror image of the cascading soft delete).
     */
    public function restore(Page $page): RedirectResponse
    {
        if (! $page->trashed()) {
            return back();
        }

        if ($page->parent()->withTrashed()->first()?->trashed()) {
            return back()->with('error', 'لا يمكن استعادة الصفحة لأن صفحتها الأب محذوفة. استعد الصفحة الأب أولاً.');
        }

        $page->restore();

        $descendantIds = $this->descendantIds($page->id, Page::withTrashed()->whereNotNull('parent_id')->get(['id', 'parent_id'])->groupBy('parent_id'));

        foreach (Page::onlyTrashed()->findMany($descendantIds) as $descendant) {
            $descendant->restore();
        }

        return back()->with('success', 'تمت استعادة الصفحة.');
    }

    /**
     * Permanently delete a trashed page. Blocked while non-deleted children
     * still point at it (the database would cascade-delete them silently).
     */
    public function forceDestroy(Page $page): RedirectResponse
    {
        if (! $page->trashed()) {
            return back()->with('error', 'يمكن الحذف النهائي للصفحات المحذوفة فقط.');
        }

        if ($page->children()->exists()) {
            return back()->with('error', 'لا يمكن الحذف النهائي لأن لهذه الصفحة صفحات فرعية غير محذوفة.');
        }

        $page->forceDelete();

        return back()->with('success', 'تم حذف الصفحة نهائياً.');
    }

    /**
     * Persist a new sibling order within one parent from an ordered array of
     * ids. Deliberately not Spatie's `setNewOrder()`: that runs bulk
     * query-builder updates which bypass model events, so the cache flush in
     * `Page::booted()` would never fire. Saving each dirty model keeps the
     * frozen cache-invalidation contract intact.
     */
    public function reorder(ReorderPagesRequest $request): RedirectResponse
    {
        $ids = $request->validated('ids');
        $pages = Page::query()->findMany($ids)->keyBy('id');

        foreach ($ids as $index => $id) {
            $pages[$id]->update(['order' => $index + 1]);
        }

        return back();
    }

    /**
     * Whether the page has real content: a non-blank raw value that is not
     * just an empty TipTap document.
     */
    protected function hasContent(Page $page): bool
    {
        $content = $page->html_content;

        if (blank($content)) {
            return false;
        }

        return ! (is_array($content) && blank($content['content'] ?? null));
    }

    /**
     * Slug parity with the original admin form (`'/'.str($title)->slug()`), plus
     * the Telegram bot's numeric-suffix collision strategy since the create
     * dialog has no slug field to fix a collision manually. Trashed pages
     * count as collisions because the column has a unique index.
     */
    protected function generateUniqueSlug(string $title): string
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

    /**
     * The page's ancestors from the root down to its direct parent.
     *
     * @param  Collection<int, Page>  $pagesById
     * @return array<int, array{id: int, title: string}>
     */
    protected function parentChain(Page $page, Collection $pagesById): array
    {
        $chain = [];
        $current = $page->parent_id === null ? null : $pagesById->get($page->parent_id);

        while ($current !== null) {
            array_unshift($chain, ['id' => $current->id, 'title' => $current->title]);
            $current = $current->parent_id === null ? null : $pagesById->get($current->parent_id);
        }

        return $chain;
    }

    /**
     * Depth-first flat list of the live tree for the parent picker.
     *
     * The depth is computed from the tree itself rather than the stored
     * `level` column, which the original admin panel never maintained on parent changes.
     *
     * @param  Collection<int|string, Collection<int, Page>>  $livePagesByParent
     * @return array<int, array{id: int, title: string, level: int}>
     */
    protected function flattenTree(Collection $livePagesByParent): array
    {
        $options = [];

        $walk = function (int $parentId, int $level) use (&$walk, &$options, $livePagesByParent): void {
            foreach ($livePagesByParent->get($parentId, collect()) as $page) {
                $options[] = ['id' => $page->id, 'title' => $page->title, 'level' => $level];
                $walk($page->id, $level + 1);
            }
        };

        $walk(0, 0);

        return $options;
    }

    /**
     * Ids of every descendant of the given page within the given
     * parent-grouped collection.
     *
     * @param  Collection<int|string, Collection<int, Page>>  $childrenByParent
     * @return array<int, int>
     */
    protected function descendantIds(int $pageId, Collection $childrenByParent): array
    {
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
