<?php

namespace App\Http\Requests\Manage;

use App\Models\DailyQuiz;
use Illuminate\Validation\Validator;

class UpdateDailyQuizRequest extends StoreDailyQuizRequest
{
    /**
     * A posted question is history the scoring depends on — reject the whole
     * edit up front so the admin gets the reason, not a field-by-field diff
     * of a save that was never going to happen.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $quiz = $this->quiz();

                if ($quiz !== null && ! $quiz->isReady()) {
                    $validator->errors()->add('quiz', 'لا يمكن تعديل سؤال بعد نشره.');
                }
            },
        ];
    }

    protected function editedQuizKey(): ?int
    {
        return $this->quiz()?->getKey();
    }

    private function quiz(): ?DailyQuiz
    {
        $quiz = $this->route('quiz');

        return $quiz instanceof DailyQuiz ? $quiz : null;
    }
}
