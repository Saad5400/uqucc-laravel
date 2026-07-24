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
     * topic's id to force that theme.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
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
            'topic_id.integer' => 'الموضوع المختار غير صالح.',
            'topic_id.exists' => 'الموضوع المختار غير موجود أو غير مفعّل.',
        ];
    }
}
