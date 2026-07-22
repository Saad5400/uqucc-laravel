<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuizSettingsRequest extends FormRequest
{
    /**
     * Any panel user may manage the quiz settings (parity with the Telegram settings page).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize scalar chat IDs to trimmed strings so numeric payloads validate consistently.
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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'chat_ids' => ['present', 'array'],
            'chat_ids.*' => ['string', 'regex:/^-?\d+$/', 'distinct'],
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
            'chat_ids.*.regex' => 'معرّف المجموعة يجب أن يكون رقماً صحيحاً (يبدأ بإشارة سالبة للمجموعات).',
            'chat_ids.*.distinct' => 'معرّف المجموعة مكرر.',
        ];
    }
}
