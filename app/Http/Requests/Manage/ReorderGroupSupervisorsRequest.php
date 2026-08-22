<?php

namespace App\Http\Requests\Manage;

use App\Models\StudentGroup\GroupSupervisor;
use App\Models\StudentGroup\StudentGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReorderGroupSupervisorsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `can:manage-student-groups` route middleware.
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
            'ids.*' => ['integer', 'distinct', 'exists:group_supervisors,id'],
        ];
    }

    /**
     * Each section is its own ordered list, so a reorder payload must name
     * supervisors from one section of the group in the URL — anything else is a
     * stale client sending another group's (or another section's) ids.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $group = $this->route('group');

                if (! $group instanceof StudentGroup) {
                    return;
                }

                $supervisors = GroupSupervisor::query()->findMany($this->input('ids'));

                if ($supervisors->contains(fn (GroupSupervisor $supervisor) => $supervisor->student_group_id !== $group->id)) {
                    $validator->errors()->add('ids', 'قائمة الترتيب تحتوي على مشرف من قروب آخر.');

                    return;
                }

                if ($supervisors->pluck('section')->unique()->count() > 1) {
                    $validator->errors()->add('ids', 'لا يمكن ترتيب مشرفين من شطرين مختلفين معاً.');
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
            'ids.required' => 'قائمة الترتيب مطلوبة.',
            'ids.array' => 'قائمة الترتيب غير صالحة.',
            'ids.min' => 'قائمة الترتيب لا يمكن أن تكون فارغة.',
            'ids.*.integer' => 'معرّف المشرف غير صالح.',
            'ids.*.distinct' => 'قائمة الترتيب تحتوي على معرّف مكرر.',
            'ids.*.exists' => 'أحد المشرفين في قائمة الترتيب غير موجود.',
        ];
    }
}
