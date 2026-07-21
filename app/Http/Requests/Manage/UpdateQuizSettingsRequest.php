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

    protected function prepareForValidation(): void
    {
        $chatId = $this->input('chat_id');

        if (is_scalar($chatId)) {
            $this->merge(['chat_id' => trim((string) $chatId) === '' ? null : trim((string) $chatId)]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'chat_id' => ['nullable', 'string', 'regex:/^-?\d+$/'],
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
            'chat_id.regex' => 'معرّف المجموعة يجب أن يكون رقماً صحيحاً (يبدأ بإشارة سالبة للمجموعات).',
        ];
    }
}
