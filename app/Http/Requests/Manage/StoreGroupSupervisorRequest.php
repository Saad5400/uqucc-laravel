<?php

namespace App\Http\Requests\Manage;

use App\Models\StudentGroup\StudentGroup;

class StoreGroupSupervisorRequest extends GroupSupervisorRequest
{
    /** The group is the route's own parameter. */
    protected function groupId(): ?int
    {
        $group = $this->route('group');

        return $group instanceof StudentGroup ? $group->id : null;
    }

    /** Nothing to exempt: no row exists yet. */
    protected function ignoreId(): ?int
    {
        return null;
    }

    /**
     * Name and section are required outright; the contacts are checked together
     * afterwards, since either one on its own is enough.
     *
     * @return array<int, string>
     */
    protected function presence(): array
    {
        return [];
    }
}
