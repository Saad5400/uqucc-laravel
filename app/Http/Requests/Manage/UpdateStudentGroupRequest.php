<?php

namespace App\Http\Requests\Manage;

use App\Models\StudentGroup\StudentGroup;

class UpdateStudentGroupRequest extends StudentGroupRequest
{
    /** A group never moves between intakes, so the intake comes from the row. */
    protected function cohortId(): ?int
    {
        $group = $this->route('group');

        return $group instanceof StudentGroup ? $group->student_cohort_id : null;
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
