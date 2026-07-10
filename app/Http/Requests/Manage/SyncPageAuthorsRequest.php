<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class SyncPageAuthorsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Pages are editable by every panel user (parity with the original
     * admin panel, where page CRUD was gated on panel access only).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `user_ids` is an ordered array of user ids: the pivot `order` is
     * derived from each id's position (1-based). An empty array detaches
     * every author.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
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
            'user_ids.present' => 'قائمة المؤلفين مطلوبة.',
            'user_ids.array' => 'قائمة المؤلفين غير صالحة.',
            'user_ids.*.integer' => 'معرّف المستخدم غير صالح.',
            'user_ids.*.distinct' => 'قائمة المؤلفين تحتوي على مستخدم مكرر.',
            'user_ids.*.exists' => 'أحد المستخدمين في القائمة غير موجود.',
        ];
    }
}
