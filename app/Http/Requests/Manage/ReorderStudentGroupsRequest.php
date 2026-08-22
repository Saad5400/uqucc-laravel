<?php

namespace App\Http\Requests\Manage;

use App\Models\StudentGroup\Cohort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReorderStudentGroupsRequest extends FormRequest
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
            'ids.*' => ['integer', 'distinct', 'exists:student_groups,id'],
        ];
    }

    /**
     * Groups are ordered within their own intake, so a payload naming another
     * intake's group is a stale client, not a reorder.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $cohort = $this->route('cohort');

                if (! $cohort instanceof Cohort) {
                    return;
                }

                $belonging = $cohort->groups()->whereIn('student_groups.id', $this->input('ids'))->count();

                if ($belonging !== count($this->input('ids'))) {
                    $validator->errors()->add('ids', 'قائمة الترتيب تحتوي على قروب من دفعة أخرى.');
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
            'ids.*.integer' => 'معرّف القروب غير صالح.',
            'ids.*.distinct' => 'قائمة الترتيب تحتوي على معرّف مكرر.',
            'ids.*.exists' => 'أحد القروبات في قائمة الترتيب غير موجود.',
        ];
    }
}
