<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTelegramSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Any panel user may manage the Telegram settings (parity with the Filament page).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize scalar chat IDs to strings so numeric payloads validate consistently.
     */
    protected function prepareForValidation(): void
    {
        $chatIds = $this->input('allowed_chat_ids');

        if (is_array($chatIds)) {
            $this->merge([
                'allowed_chat_ids' => array_map(
                    fn (mixed $chatId) => is_scalar($chatId) ? trim((string) $chatId) : $chatId,
                    $chatIds,
                ),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Chat IDs are integers as strings; group chats have negative IDs.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'allowed_chat_ids' => ['present', 'array'],
            'allowed_chat_ids.*' => ['string', 'regex:/^-?\d+$/', 'distinct'],
            'auto_delete_messages' => ['required', 'boolean'],
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
            'allowed_chat_ids.present' => 'حقل معرّفات المحادثات مطلوب.',
            'allowed_chat_ids.array' => 'قائمة معرّفات المحادثات غير صالحة.',
            'allowed_chat_ids.*.string' => 'معرّف المحادثة غير صالح.',
            'allowed_chat_ids.*.regex' => 'معرّف المحادثة يجب أن يكون رقماً صحيحاً (قد يبدأ بإشارة سالبة للمجموعات).',
            'allowed_chat_ids.*.distinct' => 'معرّف المحادثة مكرر.',
            'auto_delete_messages.required' => 'حقل الحذف التلقائي مطلوب.',
            'auto_delete_messages.boolean' => 'قيمة الحذف التلقائي غير صالحة.',
        ];
    }
}
