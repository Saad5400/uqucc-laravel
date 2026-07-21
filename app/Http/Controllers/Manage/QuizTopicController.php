<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\StoreQuizTopicRequest;
use App\Http\Requests\Manage\UpdateQuizTopicRequest;
use App\Models\QuizTopic;
use Illuminate\Http\RedirectResponse;

/**
 * CRUD for the admin-curated topics the daily quiz generates from.
 */
class QuizTopicController extends Controller
{
    public function store(StoreQuizTopicRequest $request): RedirectResponse
    {
        QuizTopic::create([
            'name' => $request->validated('name'),
            'prompt_hint' => $request->validated('prompt_hint'),
            'is_spotlight' => $request->boolean('is_spotlight'),
            'is_active' => true,
        ]);

        return back()->with('success', 'تمت إضافة الموضوع.');
    }

    public function update(UpdateQuizTopicRequest $request, QuizTopic $topic): RedirectResponse
    {
        $topic->update([
            'name' => $request->validated('name'),
            'prompt_hint' => $request->validated('prompt_hint'),
            'is_spotlight' => $request->boolean('is_spotlight'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return back();
    }

    public function destroy(QuizTopic $topic): RedirectResponse
    {
        $topic->delete();

        return back()->with('success', 'تم حذف الموضوع.');
    }
}
