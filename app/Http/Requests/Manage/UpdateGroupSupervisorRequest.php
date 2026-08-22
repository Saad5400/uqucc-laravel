<?php

namespace App\Http\Requests\Manage;

use App\Models\StudentGroup\GroupSupervisor;

class UpdateGroupSupervisorRequest extends GroupSupervisorRequest
{
    /** A supervisor never moves between groups, so the group comes from the row. */
    protected function groupId(): ?int
    {
        $supervisor = $this->route('supervisor');

        return $supervisor instanceof GroupSupervisor ? $supervisor->student_group_id : null;
    }

    /** Keeping the same contacts while editing is not a duplicate. */
    protected function ignoreId(): ?int
    {
        $supervisor = $this->route('supervisor');

        return $supervisor instanceof GroupSupervisor ? $supervisor->id : null;
    }

    /**
     * Omitted fields keep their stored value; a field that IS sent still has to
     * be valid, so flipping the availability switch never blanks a name.
     *
     * @return array<int, string>
     */
    protected function presence(): array
    {
        return ['sometimes'];
    }
}
