<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTelegramChatSettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Any panel user may manage the bot's per-chat AI activation (parity
     * with the previous admin resource).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'ai_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * Get the custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ai_enabled.required' => 'حقل المساعد الذكي مطلوب.',
            'ai_enabled.boolean' => 'قيمة المساعد الذكي غير صالحة.',
        ];
    }
}
