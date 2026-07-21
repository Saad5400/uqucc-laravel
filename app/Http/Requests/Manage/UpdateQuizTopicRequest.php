<?php

namespace App\Http\Requests\Manage;

class UpdateQuizTopicRequest extends StoreQuizTopicRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'is_active.required' => 'حقل التفعيل مطلوب.',
            'is_active.boolean' => 'قيمة التفعيل غير صالحة.',
        ];
    }
}
