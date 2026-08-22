<?php

namespace App\Http\Requests\Manage;

use App\Models\StudentGroup\Cohort;

class StoreStudentGroupRequest extends StudentGroupRequest
{
    /**
     * The intake in the URL, plus any others the admin ticked.
     *
     * @return array<int, int>
     */
    protected function cohortIds(): array
    {
        $cohort = $this->route('cohort');
        $ids = array_map('intval', $this->input('cohort_ids', []));

        if ($cohort instanceof Cohort) {
            $ids[] = $cohort->id;
        }

        return array_values(array_unique($ids));
    }

    /** Nothing to exempt: no row exists yet. */
    protected function ignoreId(): ?int
    {
        return null;
    }

    /**
     * Both parts of the pair must be stated, including stating them as empty —
     * «no programme» is what makes a group a global one, not an oversight.
     *
     * @return array<int, string>
     */
    protected function presence(): array
    {
        return ['present'];
    }
}
