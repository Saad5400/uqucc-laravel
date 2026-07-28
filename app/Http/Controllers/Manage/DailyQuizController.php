<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\StoreDailyQuizRequest;
use App\Http\Requests\Manage\UpdateDailyQuizRequest;
use App\Models\DailyQuiz;
use Illuminate\Http\RedirectResponse;

/**
 * Hand-written questions and admin edits to generated ones — the automatic
 * quality gate's manual escape hatch, and the fallback for days the authoring
 * model is unavailable. Only `ready` (not yet posted) quizzes can change:
 * what the group already saw is history, and scoring depends on it.
 */
class DailyQuizController extends Controller
{
    public function store(StoreDailyQuizRequest $request): RedirectResponse
    {
        DailyQuiz::create([
            ...$request->quizAttributes(),
            'status' => DailyQuiz::STATUS_READY,
        ]);

        return back()->with('success', 'تمت إضافة السؤال.');
    }

    public function update(UpdateDailyQuizRequest $request, DailyQuiz $quiz): RedirectResponse
    {
        if (! $quiz->isReady()) {
            return back()->withErrors(['quiz' => 'لا يمكن تعديل سؤال بعد نشره.']);
        }

        $quiz->update($request->quizAttributes());

        return back()->with('success', 'تم حفظ السؤال.');
    }

    public function destroy(DailyQuiz $quiz): RedirectResponse
    {
        if (! $quiz->isReady()) {
            return back()->withErrors(['quiz' => 'لا يمكن حذف سؤال بعد نشره.']);
        }

        $quiz->delete();

        return back()->with('success', 'تم حذف السؤال.');
    }
}
