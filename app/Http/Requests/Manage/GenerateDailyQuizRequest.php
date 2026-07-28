<?php

namespace App\Http\Requests\Manage;

use App\Models\QuizTopic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateDailyQuizRequest extends FormRequest
{
    /**
     * Any panel user may trigger generation (parity with the quiz settings).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `topic_id` is optional: omit it to let the author pick a topic
     * automatically (least-recently-used, spotlight-aware), or pass an active
     * topic's id to force that theme. `date` is the day to generate for —
     * omit it for today. Past days are refused: only today's question is ever
     * posted, so a back-dated generation would spend budget on a question
     * nobody will see (`quiz:generate --date` stays unrestricted as a
     * backfill escape hatch).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date', 'after_or_equal:today'],
            'topic_id' => [
                'nullable',
                'integer',
                Rule::exists(QuizTopic::class, 'id')->where('is_active', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date' => 'تاريخ التوليد غير صالح.',
            'date.after_or_equal' => 'لا يمكن توليد سؤال ليوم مضى — اختر اليوم أو يوماً قادماً.',
            'topic_id.integer' => 'الموضوع المختار غير صالح.',
            'topic_id.exists' => 'الموضوع المختار غير موجود أو غير مفعّل.',
        ];
    }
}
