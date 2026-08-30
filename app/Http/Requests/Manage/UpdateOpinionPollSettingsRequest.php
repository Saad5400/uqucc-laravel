<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOpinionPollSettingsRequest extends FormRequest
{
    /** The longest a poll may stay open — a week, past which nobody remembers voting. */
    public const MAX_OPEN_HOURS = 168;

    /**
     * Any panel user may manage the opinion poll settings (parity with the
     * quiz settings page).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize scalar chat IDs to trimmed strings so numeric payloads
     * validate consistently.
     */
    protected function prepareForValidation(): void
    {
        $chatIds = $this->input('chat_ids');

        if (is_array($chatIds)) {
            $this->merge([
                'chat_ids' => array_map(
                    fn (mixed $chatId) => is_scalar($chatId) ? trim((string) $chatId) : $chatId,
                    $chatIds,
                ),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'chat_ids' => ['present', 'array'],
            'chat_ids.*' => ['string', 'regex:/^-?\d+(?::\d+)?$/', 'distinct'],
            'post_time' => ['required', 'date_format:H:i'],
            'open_hours' => ['required', 'integer', 'between:1,'.self::MAX_OPEN_HOURS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'enabled.required' => 'حقل التفعيل مطلوب.',
            'enabled.boolean' => 'قيمة التفعيل غير صالحة.',
            'chat_ids.present' => 'حقل المجموعات مطلوب.',
            'chat_ids.array' => 'قائمة المجموعات غير صالحة.',
            'chat_ids.*.string' => 'معرّف المجموعة غير صالح.',
            'chat_ids.*.regex' => 'معرّف المجموعة يجب أن يكون رقماً صحيحاً (يبدأ بإشارة سالبة للمجموعات)، ويمكن إضافة معرّف موضوع بعد نقطتين مثل ‎-100…:42‎.',
            'chat_ids.*.distinct' => 'معرّف المجموعة مكرر.',
            'post_time.required' => 'حدد موعد النشر.',
            'post_time.date_format' => 'موعد النشر يجب أن يكون بصيغة الساعة والدقيقة، مثل 20:00.',
            'open_hours.required' => 'حدد مدة بقاء الاستطلاع مفتوحاً.',
            'open_hours.integer' => 'مدة الاستطلاع يجب أن تكون عدد ساعات.',
            'open_hours.between' => 'مدة الاستطلاع يجب أن تكون بين ساعة و'.self::MAX_OPEN_HOURS.' ساعة.',
        ];
    }
}
