<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\StoreTelegramTeamCategoryRequest;
use App\Http\Requests\Manage\UpdateTelegramTeamCategoryRequest;
use App\Models\TelegramTeamCategory;
use Illuminate\Http\RedirectResponse;

/**
 * The teams-only categories («التخصص», «الفرع»…) of one chat. Deleting a
 * category never touches its teams — the FK nulls and they become
 * uncategorized.
 */
class TelegramTeamCategoryController extends Controller
{
    public function store(StoreTelegramTeamCategoryRequest $request, string $chatId): RedirectResponse
    {
        TelegramTeamCategory::query()->create([
            'chat_id' => (int) $chatId,
            'name' => $request->string('name')->trim()->value(),
        ]);

        return back()->with('success', 'تم إنشاء التصنيف.');
    }

    public function update(UpdateTelegramTeamCategoryRequest $request, TelegramTeamCategory $category): RedirectResponse
    {
        $category->update(['name' => $request->string('name')->trim()->value()]);

        return back();
    }

    public function destroy(TelegramTeamCategory $category): RedirectResponse
    {
        $category->delete();

        return back()->with('success', 'تم حذف التصنيف — بقيت فرقه بدون تصنيف.');
    }
}
