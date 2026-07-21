<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuizTopicRequest extends FormRequest
{
    /**
     * Any panel user may manage quiz topics (parity with the quiz settings).
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
            'name' => ['required', 'string', 'max:255'],
            'prompt_hint' => ['nullable', 'string', 'max:2000'],
            'is_spotlight' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم الموضوع مطلوب.',
            'name.max' => 'اسم الموضوع طويل جداً.',
            'prompt_hint.max' => 'توجيهات الموضوع طويلة جداً (الحد 2000 حرف).',
            'is_spotlight.required' => 'حقل يوم التخصص مطلوب.',
            'is_spotlight.boolean' => 'قيمة يوم التخصص غير صالحة.',
        ];
    }
}
