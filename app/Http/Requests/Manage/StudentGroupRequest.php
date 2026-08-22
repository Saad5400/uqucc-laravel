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
 * identified by what it is for — its programme and branch inside one intake —
 * so the pair has to stay unique: two rows for the same combination would split
 * one supervisor list into two half-lists the public filter shows side by side.
 */
abstract class StudentGroupRequest extends FormRequest
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

    /** The intake the group belongs to. */
    abstract protected function cohortId(): ?int;

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
        ];
    }

    /**
     * Reject a programme/branch pair the intake already has.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->cohortId() === null) {
                    return;
                }

                if (! $this->has('major') && ! $this->has('branch')) {
                    return;
                }

                $taken = StudentGroup::query()
                    ->where('student_cohort_id', $this->cohortId())
                    ->where('major', $this->resolved('major'))
                    ->where('branch', $this->resolved('branch'))
                    ->when($this->ignoreId() !== null, fn ($query) => $query->whereKeyNot($this->ignoreId()))
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('major', 'هذا التخصص مضاف مسبقاً لهذا الفرع في هذه الدفعة.');
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
            'major.required' => 'حقل التخصص مطلوب. اتركه فارغاً لإنشاء القروب العام.',
            'major.in' => 'التخصص المحدد غير صالح.',
            'branch.required' => 'حقل الفرع مطلوب. اتركه فارغاً ليشمل كل الفروع.',
            'branch.in' => 'الفرع المحدد غير صالح.',
            'is_active.boolean' => 'حالة العرض غير صالحة.',
        ];
    }
}
