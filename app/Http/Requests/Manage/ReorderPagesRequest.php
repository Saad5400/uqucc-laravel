<?php

namespace App\Http\Requests\Manage;

use App\Models\Page;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReorderPagesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Pages are editable by every panel user (parity with the Filament
     * panel, where page CRUD is gated on panel access only).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, \Illuminate\Validation\Rules\Exists|string>>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', Rule::exists('pages', 'id')->whereNull('deleted_at')],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', Rule::exists('pages', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * Only siblings of one parent may be reordered together: every page in
     * the list must currently belong to the given parent.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $parentId = $this->input('parent_id') === null ? null : (int) $this->input('parent_id');
                $pages = Page::query()->findMany($this->input('ids', []));

                if ($pages->contains(fn (Page $page) => $page->parent_id !== $parentId)) {
                    $validator->errors()->add('ids', 'إحدى الصفحات في قائمة الترتيب لا تنتمي إلى نفس الصفحة الأب.');
                }
            },
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
            'parent_id.integer' => 'معرّف الصفحة الأب غير صالح.',
            'parent_id.exists' => 'الصفحة الأب المحددة غير موجودة.',
            'ids.required' => 'قائمة الترتيب مطلوبة.',
            'ids.array' => 'قائمة الترتيب غير صالحة.',
            'ids.min' => 'قائمة الترتيب لا يمكن أن تكون فارغة.',
            'ids.*.integer' => 'معرّف الصفحة غير صالح.',
            'ids.*.distinct' => 'قائمة الترتيب تحتوي على معرّف مكرر.',
            'ids.*.exists' => 'إحدى الصفحات في قائمة الترتيب غير موجودة.',
        ];
    }
}
