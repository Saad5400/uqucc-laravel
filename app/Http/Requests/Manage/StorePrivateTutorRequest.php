<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class StorePrivateTutorRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'url', 'max:255'],
            'course_ids' => ['sometimes', 'array'],
            'course_ids.*' => ['integer', 'exists:private_tutor_courses,id'],
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
            'name.required' => 'حقل الاسم مطلوب.',
            'name.string' => 'يجب أن يكون الاسم نصاً.',
            'name.max' => 'يجب ألا يتجاوز الاسم ٢٥٥ حرفاً.',
            'url.string' => 'يجب أن يكون الرابط نصاً.',
            'url.url' => 'يجب إدخال رابط صالح يبدأ بـ https:// أو http://.',
            'url.max' => 'يجب ألا يتجاوز الرابط ٢٥٥ حرفاً.',
            'course_ids.array' => 'قائمة المقررات غير صالحة.',
            'course_ids.*.integer' => 'معرّف المقرر غير صالح.',
            'course_ids.*.exists' => 'أحد المقررات المحددة غير موجود.',
        ];
    }
}
