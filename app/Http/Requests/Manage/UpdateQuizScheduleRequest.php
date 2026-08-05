<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuizScheduleRequest extends FormRequest
{
    /** Reschedule «اليوم فقط» — this day's question only. */
    public const SCOPE_TODAY = 'today';

    /** Reschedule «كل الأيام» — the default posting time. */
    public const SCOPE_DEFAULT = 'default';

    /**
     * Any panel user may reschedule the quiz (parity with the quiz settings).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `post_time` is «HH:MM» in the app timezone. It may only be null for the
     * one-day scope, where clearing it drops the override and returns that day
     * to the default time.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in([self::SCOPE_TODAY, self::SCOPE_DEFAULT])],
            'post_time' => [
                $this->input('scope') === self::SCOPE_TODAY ? 'nullable' : 'required',
                'date_format:H:i',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scope.required' => 'حدد نطاق الجدولة.',
            'scope.in' => 'نطاق الجدولة غير صالح.',
            'post_time.required' => 'حدد موعد النشر.',
            'post_time.date_format' => 'موعد النشر يجب أن يكون بصيغة الساعة والدقيقة، مثل 16:00.',
        ];
    }
}
