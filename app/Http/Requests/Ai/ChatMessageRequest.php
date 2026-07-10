<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class ChatMessageRequest extends FormRequest
{
    /**
     * The endpoint is public (anonymous, session-identified); feature and
     * budget gating happen in the controller against AiSettings/SpendLedger
     * so disabled states yield a consistent JSON shape.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:2000'],
            'conversation_id' => ['sometimes', 'nullable', 'string', 'max:36'],
            'attachment_ids' => ['sometimes', 'array', 'max:5'],
            'attachment_ids.*' => ['string', 'ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'اكتب رسالة أولاً.',
            'message.string' => 'يجب أن تكون الرسالة نصاً.',
            'message.min' => 'اكتب رسالة أولاً.',
            'message.max' => 'يجب ألا تتجاوز الرسالة ٢٠٠٠ حرف.',
            'conversation_id.string' => 'معرف المحادثة غير صالح.',
            'conversation_id.max' => 'معرف المحادثة غير صالح.',
            'attachment_ids.array' => 'قائمة المرفقات غير صالحة.',
            'attachment_ids.max' => 'يمكن إرفاق ٥ ملفات كحد أقصى في الرسالة الواحدة.',
            'attachment_ids.*.string' => 'معرف المرفق غير صالح.',
            'attachment_ids.*.ulid' => 'معرف المرفق غير صالح.',
        ];
    }
}
