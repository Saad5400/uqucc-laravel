<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\ReorderGroupSupervisorsRequest;
use App\Http\Requests\Manage\StoreGroupSupervisorRequest;
use App\Http\Requests\Manage\UpdateGroupSupervisorRequest;
use App\Models\StudentGroup\GroupSupervisor;
use App\Models\StudentGroup\StudentGroup;
use Illuminate\Http\RedirectResponse;

class GroupSupervisorController extends Controller
{
    /** The columns every write path accepts. */
    private const FIELDS = [
        'name',
        'telegram_username',
        'whatsapp_number',
        'section',
        'is_available',
    ];

    /**
     * Add a supervisor to a group.
     */
    public function store(StoreGroupSupervisorRequest $request, StudentGroup $group): RedirectResponse
    {
        $group->supervisors()->create($request->safe()->only(self::FIELDS));

        return back();
    }

    /**
     * Update a supervisor. Accepts partial payloads so the availability toggle
     * in the list can send `is_available` on its own.
     */
    public function update(UpdateGroupSupervisorRequest $request, GroupSupervisor $supervisor): RedirectResponse
    {
        $supervisor->update($request->safe()->only(self::FIELDS));

        return back();
    }

    /**
     * Remove a supervisor from a group.
     */
    public function destroy(GroupSupervisor $supervisor): RedirectResponse
    {
        $supervisor->delete();

        return back();
    }

    /**
     * Persist a new order within one section of a group.
     *
     * Each section is ordered independently, so the positions handed out here
     * are 1..n within the posted section and may repeat across sections — the
     * public page buckets by section before ordering, so only the relative
     * order inside a section is ever read.
     *
     * Deliberately not Spatie's `setNewOrder()`: that bypasses model events and
     * would leave the public cache holding the old order.
     */
    public function reorder(ReorderGroupSupervisorsRequest $request, StudentGroup $group): RedirectResponse
    {
        $ids = $request->validated('ids');
        $supervisors = $group->supervisors()->findMany($ids)->keyBy('id');

        foreach ($ids as $index => $id) {
            $supervisors[$id]->update(['order' => $index + 1]);
        }

        return back();
    }
}
