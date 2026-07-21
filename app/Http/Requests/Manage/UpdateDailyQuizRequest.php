<?php

namespace App\Http\Requests\Manage;

use App\Ai\Quiz\QuizAuthor;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDailyQuizRequest extends FormRequest
{
    /**
     * Any panel user may edit a not-yet-posted quiz (parity with the quiz settings).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Length limits mirror Telegram's quiz-poll hard limits — the content is
     * sent verbatim via sendPoll.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:'.QuizAuthor::MAX_QUESTION_CHARS],
            'options' => ['required', 'array', 'size:4'],
            'options.*' => ['required', 'string', 'max:'.QuizAuthor::MAX_OPTION_CHARS, 'distinct'],
            'correct_option' => ['required', 'integer', 'between:0,3'],
            'explanation' => ['nullable', 'string', 'max:'.QuizAuthor::MAX_EXPLANATION_CHARS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'question.required' => 'نص السؤال مطلوب.',
            'question.max' => 'السؤال أطول من حد تيليجرام (300 حرف).',
            'options.required' => 'الخيارات مطلوبة.',
            'options.size' => 'يجب أن تكون الخيارات أربعة بالضبط.',
            'options.*.required' => 'لا يمكن ترك خيار فارغاً.',
            'options.*.max' => 'الخيار أطول من حد تيليجرام (100 حرف).',
            'options.*.distinct' => 'الخيارات متكررة.',
            'correct_option.required' => 'حدد الإجابة الصحيحة.',
            'correct_option.between' => 'الإجابة الصحيحة يجب أن تكون أحد الخيارات الأربعة.',
            'explanation.max' => 'الشرح أطول من حد تيليجرام (200 حرف).',
        ];
    }
}
