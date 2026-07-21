<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\UpdateDailyQuizRequest;
use App\Models\DailyQuiz;
use Illuminate\Http\RedirectResponse;

/**
 * Admin edits to generated questions — the automatic quality gate's manual
 * escape hatch. Only `ready` (not yet posted) quizzes can change: what the
 * group already saw is history, and scoring depends on it.
 */
class DailyQuizController extends Controller
{
    public function update(UpdateDailyQuizRequest $request, DailyQuiz $quiz): RedirectResponse
    {
        if (! $quiz->isReady()) {
            return back()->withErrors(['quiz' => 'لا يمكن تعديل سؤال بعد نشره.']);
        }

        $quiz->update([
            'question' => $request->validated('question'),
            'options' => array_values($request->validated('options')),
            'correct_option' => (int) $request->validated('correct_option'),
            'explanation' => $request->validated('explanation'),
        ]);

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
