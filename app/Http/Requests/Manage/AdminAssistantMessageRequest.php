<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class AdminAssistantMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The route group already requires panel access; any panel user may talk
     * to the admin assistant (parity with the settings page).
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
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string', 'max:64'],
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
            'message.required' => 'اكتب رسالة أولاً.',
            'message.max' => 'الرسالة أطول من الحد المسموح (٢٠٠٠ حرف).',
            'conversation_id.max' => 'معرّف المحادثة غير صالح.',
        ];
    }
}
