<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCohortRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `manage.access` route middleware, which
     * gates the whole panel.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Partial payloads are accepted so the switches in the cohort list can
     * send one field alone; anything that IS sent still has to be valid.
     * Blank checklist rows are tolerated rather than rejected: the editor drops
     * them on save, so a stray empty field is never an error the admin has to go
     * back and fix.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'requirements' => ['sometimes', 'array', 'max:10'],
            'requirements.*' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
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
            'name.required' => 'حقل اسم الدفعة مطلوب.',
            'name.string' => 'يجب أن يكون اسم الدفعة نصاً.',
            'name.max' => 'يجب ألا يتجاوز اسم الدفعة ٢٥٥ حرفاً.',
            'description.string' => 'يجب أن يكون التعريف نصاً.',
            'description.max' => 'يجب ألا يتجاوز التعريف ١٠٠٠ حرف.',
            'note.string' => 'يجب أن يكون التنويه نصاً.',
            'note.max' => 'يجب ألا يتجاوز التنويه ٥٠٠ حرف.',
            'requirements.array' => 'قائمة الشروط غير صالحة.',
            'requirements.max' => 'يجب ألا تتجاوز الشروط ١٠ عناصر.',
            'requirements.*.string' => 'يجب أن يكون الشرط نصاً.',
            'requirements.*.max' => 'يجب ألا يتجاوز الشرط ٢٥٥ حرفاً.',
            'is_active.boolean' => 'حالة العرض غير صالحة.',
            'is_featured.boolean' => 'حالة الإبراز غير صالحة.',
        ];
    }
}
