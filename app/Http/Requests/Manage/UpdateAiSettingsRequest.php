<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Any panel user may manage the AI settings (parity with the previous admin page).
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
            'search_enabled' => ['required', 'boolean'],
            'assistant_enabled' => ['required', 'boolean'],
            'telegram_ai_enabled' => ['required', 'boolean'],
            'admin_copilot_enabled' => ['required', 'boolean'],
            'chat_model' => ['required', 'string', 'max:255'],
            'vision_model' => ['required', 'string', 'max:255'],
            'embedding_model' => ['required', 'string', 'max:255'],
            'daily_budget_usd' => ['required', 'numeric', 'min:0'],
            'per_session_rate_limit' => ['required', 'integer', 'min:1'],
            'per_conversation_rate_limit' => ['required', 'integer', 'min:1'],
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
            'ai_enabled.required' => 'حقل تفعيل الذكاء الاصطناعي مطلوب.',
            'ai_enabled.boolean' => 'قيمة تفعيل الذكاء الاصطناعي غير صالحة.',
            'search_enabled.required' => 'حقل البحث الذكي مطلوب.',
            'search_enabled.boolean' => 'قيمة البحث الذكي غير صالحة.',
            'assistant_enabled.required' => 'حقل المساعد الذكي مطلوب.',
            'assistant_enabled.boolean' => 'قيمة المساعد الذكي غير صالحة.',
            'telegram_ai_enabled.required' => 'حقل ذكاء بوت التليجرام مطلوب.',
            'telegram_ai_enabled.boolean' => 'قيمة ذكاء بوت التليجرام غير صالحة.',
            'admin_copilot_enabled.required' => 'حقل مساعد لوحة الإدارة مطلوب.',
            'admin_copilot_enabled.boolean' => 'قيمة مساعد لوحة الإدارة غير صالحة.',
            'chat_model.required' => 'حقل نموذج المحادثة مطلوب.',
            'chat_model.max' => 'معرّف نموذج المحادثة طويل جداً.',
            'vision_model.required' => 'حقل نموذج الرؤية مطلوب.',
            'vision_model.max' => 'معرّف نموذج الرؤية طويل جداً.',
            'embedding_model.required' => 'حقل نموذج التضمين مطلوب.',
            'embedding_model.max' => 'معرّف نموذج التضمين طويل جداً.',
            'daily_budget_usd.required' => 'حقل الميزانية اليومية مطلوب.',
            'daily_budget_usd.numeric' => 'الميزانية اليومية يجب أن تكون رقماً.',
            'daily_budget_usd.min' => 'الميزانية اليومية لا يمكن أن تكون سالبة.',
            'per_session_rate_limit.required' => 'حقل حد الرسائل لكل جلسة مطلوب.',
            'per_session_rate_limit.integer' => 'حد الرسائل لكل جلسة يجب أن يكون رقماً صحيحاً.',
            'per_session_rate_limit.min' => 'حد الرسائل لكل جلسة يجب أن يكون 1 على الأقل.',
            'per_conversation_rate_limit.required' => 'حقل حد الرسائل لكل محادثة مطلوب.',
            'per_conversation_rate_limit.integer' => 'حد الرسائل لكل محادثة يجب أن يكون رقماً صحيحاً.',
            'per_conversation_rate_limit.min' => 'حد الرسائل لكل محادثة يجب أن يكون 1 على الأقل.',
        ];
    }
}
