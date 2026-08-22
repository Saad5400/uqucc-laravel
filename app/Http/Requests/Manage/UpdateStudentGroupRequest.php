<?php

namespace App\Http\Requests\Manage;

use App\Models\StudentGroup\StudentGroup;

class UpdateStudentGroupRequest extends StudentGroupRequest
{
    /**
     * Where the group would live after this request: the intakes being synced
     * if the payload names them, otherwise the ones it already serves.
     *
     * @return array<int, int>
     */
    protected function cohortIds(): array
    {
        if ($this->has('cohort_ids')) {
            return array_map('intval', $this->input('cohort_ids', []));
        }

        $group = $this->route('group');

        return $group instanceof StudentGroup ? $group->cohorts()->pluck('student_cohorts.id')->all() : [];
    }

    /** Keeping the same pair while editing is not a duplicate. */
    protected function ignoreId(): ?int
    {
        $group = $this->route('group');

        return $group instanceof StudentGroup ? $group->id : null;
    }

    /**
     * Omitted fields keep their stored value.
     *
     * @return array<int, string>
     */
    protected function presence(): array
    {
        return ['sometimes'];
    }
}
