<?php

namespace App\Http\Requests\Manage;

use App\Models\OpinionPoll;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreOpinionPollRequest extends FormRequest
{
    /**
     * Any panel user may write an opinion poll (parity with the quiz).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim the text fields and drop the blank option rows the editor leaves
     * behind when an admin uses fewer than the maximum — an empty option is a
     * Telegram error, not a choice.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($question = $this->input('question'))) {
            $normalized['question'] = trim($question);
        }

        if (is_array($options = $this->input('options'))) {
            $normalized['options'] = array_values(array_filter(
                array_map(fn (mixed $option): mixed => is_string($option) ? trim($option) : $option, $options),
                fn (mixed $option): bool => $option !== '' && $option !== null,
            ));
        }

        if ($this->has('post_time') && blank($this->input('post_time'))) {
            $normalized['post_time'] = null;
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * The caps are Telegram's own poll limits, so a poll that validates here
     * is a poll the Bot API will accept.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'poll_date' => ['required', 'date'],
            'question' => ['required', 'string', 'max:'.OpinionPoll::MAX_QUESTION_CHARS],
            'options' => ['required', 'array', 'min:'.OpinionPoll::MIN_OPTIONS, 'max:'.OpinionPoll::MAX_OPTIONS],
            'options.*' => ['required', 'string', 'max:'.OpinionPoll::MAX_OPTION_CHARS, 'distinct'],
            'post_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * One poll per day — the column is unique and the poster looks a day up by
     * date. Checked here rather than with `Rule::unique` because the `date`
     * cast stores midnight timestamps, which an exact-match unique rule would
     * never hit.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('poll_date')) {
                    return;
                }

                $taken = OpinionPoll::query()
                    ->whereDate('poll_date', $this->date('poll_date'))
                    ->whereKeyNot($this->editedPollKey() ?? 0)
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('poll_date', 'يوجد استطلاع آخر بهذا التاريخ — لكل يوم استطلاع واحد.');
                }
            },
        ];
    }

    /**
     * The poll being edited, which must not collide with itself. Null while
     * writing a new one.
     */
    protected function editedPollKey(): ?int
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'poll_date.required' => 'تاريخ الاستطلاع مطلوب.',
            'poll_date.date' => 'تاريخ الاستطلاع غير صالح.',
            'question.required' => 'نص الاستطلاع مطلوب.',
            'question.max' => 'نص الاستطلاع أطول من حد تيليجرام ('.OpinionPoll::MAX_QUESTION_CHARS.' حرف).',
            'options.required' => 'الخيارات مطلوبة.',
            'options.min' => 'الاستطلاع يحتاج خيارين على الأقل.',
            'options.max' => 'تيليجرام يقبل '.OpinionPoll::MAX_OPTIONS.' خيارات كحد أقصى.',
            'options.*.required' => 'لا يمكن ترك خيار فارغاً.',
            'options.*.max' => 'الخيار أطول من حد تيليجرام ('.OpinionPoll::MAX_OPTION_CHARS.' حرف).',
            'options.*.distinct' => 'الخيارات متكررة.',
            'post_time.date_format' => 'موعد النشر يجب أن يكون بصيغة الساعة والدقيقة، مثل 20:00.',
        ];
    }

    /**
     * The validated payload shaped for an {@see OpinionPoll} write.
     *
     * @return array<string, mixed>
     */
    public function pollAttributes(): array
    {
        return [
            'poll_date' => $this->validated('poll_date'),
            'question' => $this->validated('question'),
            'options' => array_values($this->validated('options')),
            'post_time' => $this->validated('post_time'),
        ];
    }
}
