<?php

namespace App\Http\Requests\Manage;

use App\Models\StudentGroup\Branch;
use App\Models\StudentGroup\Major;
use App\Models\StudentGroup\StudentGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation for creating and editing a {@see StudentGroup}. A group is
 * identified by what it is for — its programme and branch — so within any one
 * intake that pair has to stay unique: two rows for the same combination would
 * split one supervisor list into two half-lists shown side by side.
 *
 * A group may serve several intakes, so the check runs against every intake it
 * would end up in, not just the one the admin is looking at.
 */
abstract class StudentGroupRequest extends FormRequest
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
     * Every intake this group would serve once the request is applied.
     *
     * @return array<int, int>
     */
    abstract protected function cohortIds(): array;

    /** The row exempt from the duplicate check (the one being edited). */
    abstract protected function ignoreId(): ?int;

    /**
     * How each field must be present: creating needs the pair, editing accepts
     * partial payloads so the visibility switch can travel alone.
     *
     * @return array<int, string>
     */
    abstract protected function presence(): array;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'major' => [...$this->presence(), 'nullable', Rule::in(Major::values())],
            'branch' => [...$this->presence(), 'nullable', Rule::in(Branch::values())],
            'is_active' => ['sometimes', 'boolean'],
            'cohort_ids' => ['sometimes', 'array', 'min:1'],
            'cohort_ids.*' => ['integer', 'distinct', 'exists:student_cohorts,id'],
        ];
    }

    /**
     * Reject a programme/branch pair any of the target intakes already has.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->has('major') && ! $this->has('branch') && ! $this->has('cohort_ids')) {
                    return;
                }

                $clash = StudentGroup::query()
                    ->whereHas('cohorts', fn ($query) => $query->whereIn('student_cohorts.id', $this->cohortIds()))
                    ->where('major', $this->resolved('major'))
                    ->where('branch', $this->resolved('branch'))
                    ->when($this->ignoreId() !== null, fn ($query) => $query->whereKeyNot($this->ignoreId()))
                    ->exists();

                if ($clash) {
                    $validator->errors()->add('major', 'هذا التخصص مضاف مسبقاً لهذا الفرع في إحدى الدفعات المحددة.');
                }
            },
        ];
    }

    /**
     * The value a partial payload leaves in place — the stored one when the
     * field was not sent at all.
     */
    private function resolved(string $field): ?string
    {
        if ($this->has($field)) {
            $value = $this->input($field);

            return $value === '' ? null : $value;
        }

        $group = $this->route('group');

        return $group instanceof StudentGroup ? $group->getRawOriginal($field) : null;
    }

    /**
     * Get the custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'major.present' => 'حقل التخصص مطلوب. اتركه فارغاً لإنشاء القروب العام.',
            'major.in' => 'التخصص المحدد غير صالح.',
            'branch.present' => 'حقل الفرع مطلوب. اتركه فارغاً ليشمل كل الفروع.',
            'branch.in' => 'الفرع المحدد غير صالح.',
            'is_active.boolean' => 'حالة العرض غير صالحة.',
            'cohort_ids.array' => 'قائمة الدفعات غير صالحة.',
            'cohort_ids.min' => 'يجب أن يكون القروب ضمن دفعة واحدة على الأقل.',
            'cohort_ids.*.integer' => 'معرّف الدفعة غير صالح.',
            'cohort_ids.*.exists' => 'إحدى الدفعات المحددة غير موجودة.',
        ];
    }
}
