<?php

namespace App\Http\Requests\Manage;

use App\Ai\Quiz\QuizAuthor;
use App\Models\DailyQuiz;
use App\Models\QuizTopic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDailyQuizRequest extends FormRequest
{
    /**
     * Any panel user may write a question by hand (parity with the quiz settings).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Blank optional text arrives from the form as an empty string; the
     * columns are nullable and the bot treats "" and null differently
     * (an empty hint would send an empty reminder line), so normalize once
     * here instead of in every caller.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['body', 'explanation', 'hint', 'obvious_hint'] as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $value = is_string($value) ? trim($value) : $value;
                $normalized[$field] = $value === '' ? null : $value;
            }
        }

        if (is_array($options = $this->input('options'))) {
            $normalized['options'] = array_map(
                fn (mixed $option): mixed => is_string($option) ? trim($option) : $option,
                $options,
            );
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * Length limits mirror Telegram's quiz-poll hard limits — the content is
     * sent verbatim via sendPoll — and reuse the authoring constants so the
     * hand-written and generated questions obey one set of caps.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'quiz_topic_id' => ['nullable', 'integer', Rule::exists(QuizTopic::class, 'id')],
            'quiz_date' => ['required', 'date'],
            'question' => ['required', 'string', 'max:'.QuizAuthor::MAX_QUESTION_CHARS],
            'body' => ['nullable', 'string', 'max:'.QuizAuthor::MAX_BODY_CHARS],
            'options' => ['required', 'array', 'size:4'],
            'options.*' => ['required', 'string', 'max:'.QuizAuthor::MAX_OPTION_CHARS, 'distinct'],
            'correct_option' => ['required', 'integer', 'between:0,3'],
            'explanation' => ['nullable', 'string', 'max:'.QuizAuthor::MAX_EXPLANATION_CHARS],
            'hint' => ['nullable', 'string', 'max:'.QuizAuthor::MAX_HINT_CHARS],
            'obvious_hint' => ['nullable', 'string', 'max:'.QuizAuthor::MAX_HINT_CHARS],
        ];
    }

    /**
     * One question per day — the column is unique and the poster looks a day
     * up by date. Checked here rather than with `Rule::unique` because the
     * `date` cast stores midnight timestamps, which an exact-match unique
     * rule would never hit.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('quiz_date')) {
                    return;
                }

                $taken = DailyQuiz::query()
                    ->whereDate('quiz_date', $this->date('quiz_date'))
                    ->whereKeyNot($this->editedQuizKey() ?? 0)
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('quiz_date', 'يوجد سؤال آخر بهذا التاريخ — لكل يوم سؤال واحد.');
                }
            },
        ];
    }

    /**
     * The question being edited, which must not collide with itself.
     * Null while writing a new one.
     */
    protected function editedQuizKey(): ?int
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quiz_topic_id.integer' => 'الموضوع المختار غير صالح.',
            'quiz_topic_id.exists' => 'الموضوع المختار غير موجود.',
            'quiz_date.required' => 'تاريخ السؤال مطلوب.',
            'quiz_date.date' => 'تاريخ السؤال غير صالح.',
            'question.required' => 'نص السؤال مطلوب.',
            'question.max' => 'السؤال أطول من حد تيليجرام ('.QuizAuthor::MAX_QUESTION_CHARS.' حرف).',
            'body.max' => 'محتوى السؤال (الكود/المقدمة) أطول من الحد ('.QuizAuthor::MAX_BODY_CHARS.' حرف).',
            'options.required' => 'الخيارات مطلوبة.',
            'options.size' => 'يجب أن تكون الخيارات أربعة بالضبط.',
            'options.*.required' => 'لا يمكن ترك خيار فارغاً.',
            'options.*.max' => 'الخيار أطول من حد تيليجرام ('.QuizAuthor::MAX_OPTION_CHARS.' حرف).',
            'options.*.distinct' => 'الخيارات متكررة.',
            'correct_option.required' => 'حدد الإجابة الصحيحة.',
            'correct_option.between' => 'الإجابة الصحيحة يجب أن تكون أحد الخيارات الأربعة.',
            'explanation.max' => 'الشرح أطول من حد تيليجرام ('.QuizAuthor::MAX_EXPLANATION_CHARS.' حرف).',
            'hint.max' => 'تلميح التذكير أطول من الحد ('.QuizAuthor::MAX_HINT_CHARS.' حرف).',
            'obvious_hint.max' => 'تلميح آخر فرصة أطول من الحد ('.QuizAuthor::MAX_HINT_CHARS.' حرف).',
        ];
    }

    /**
     * The validated payload shaped for a {@see \App\Models\DailyQuiz} write.
     *
     * @return array<string, mixed>
     */
    public function quizAttributes(): array
    {
        return [
            'quiz_topic_id' => $this->validated('quiz_topic_id'),
            'quiz_date' => $this->validated('quiz_date'),
            'question' => $this->validated('question'),
            'body' => $this->validated('body'),
            'options' => array_values($this->validated('options')),
            'correct_option' => (int) $this->validated('correct_option'),
            'explanation' => $this->validated('explanation'),
            'hint' => $this->validated('hint'),
            'obvious_hint' => $this->validated('obvious_hint'),
        ];
    }
}
