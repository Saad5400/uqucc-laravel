<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\SyncPageAuthorsRequest;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;

class PageAuthorsController extends Controller
{
    /**
     * Sync the page's authors from an ordered array of user ids; the pivot
     * `order` is the 1-based array position. The page is touched afterwards
     * because pivot syncs fire no Page events, and authors render on the
     * public page — touching keeps the `Page::booted()` cache-flush
     * contract firing.
     */
    public function update(SyncPageAuthorsRequest $request, Page $page): RedirectResponse
    {
        $userIds = collect($request->validated('user_ids'))->values();

        $page->users()->sync(
            $userIds->mapWithKeys(fn (int $userId, int $index) => [$userId => ['order' => $index + 1]])->all()
        );

        $page->touch();

        return back();
    }
}
