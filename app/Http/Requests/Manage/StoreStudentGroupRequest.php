<?php

namespace App\Http\Requests\Manage;

use App\Models\StudentGroup\Cohort;

class StoreStudentGroupRequest extends StudentGroupRequest
{
    /** The intake is the route's own parameter. */
    protected function cohortId(): ?int
    {
        $cohort = $this->route('cohort');

        return $cohort instanceof Cohort ? $cohort->id : null;
    }

    /** Nothing to exempt: no row exists yet. */
    protected function ignoreId(): ?int
    {
        return null;
    }

    /**
     * Both parts of the pair must be stated, including stating them as empty —
     * «no programme» is what makes a group the general one, not an oversight.
     *
     * @return array<int, string>
     */
    protected function presence(): array
    {
        return ['present'];
    }
}
