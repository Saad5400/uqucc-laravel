<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class ReorderPrivateTutorsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `can:manage-private-tutors` route middleware.
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
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:private_tutors,id'],
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
            'ids.required' => 'قائمة الترتيب مطلوبة.',
            'ids.array' => 'قائمة الترتيب غير صالحة.',
            'ids.min' => 'قائمة الترتيب لا يمكن أن تكون فارغة.',
            'ids.*.integer' => 'معرّف الخصوصي غير صالح.',
            'ids.*.distinct' => 'قائمة الترتيب تحتوي على معرّف مكرر.',
            'ids.*.exists' => 'أحد الخصوصيين في قائمة الترتيب غير موجود.',
        ];
    }
}
