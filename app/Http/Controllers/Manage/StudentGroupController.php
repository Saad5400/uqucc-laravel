<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\ReorderStudentGroupsRequest;
use App\Http\Requests\Manage\StoreStudentGroupRequest;
use App\Http\Requests\Manage\UpdateStudentGroupRequest;
use App\Models\StudentGroup\Cohort;
use App\Models\StudentGroup\StudentGroup;
use Illuminate\Http\RedirectResponse;

class StudentGroupController extends Controller
{
    /**
     * Add a group to an intake.
     */
    public function store(StoreStudentGroupRequest $request, Cohort $cohort): RedirectResponse
    {
        $group = StudentGroup::create($this->attributesFrom($request->validated()));
        $group->cohorts()->sync($this->cohortIdsFrom($request->validated(), $cohort));

        return back();
    }

    /**
     * Update a group. Accepts partial payloads so the visibility switch in the
     * group list can send `is_active` on its own.
     */
    public function update(UpdateStudentGroupRequest $request, StudentGroup $group): RedirectResponse
    {
        $group->update($this->attributesFrom($request->validated()));

        if ($request->has('cohort_ids')) {
            $group->cohorts()->sync($request->validated('cohort_ids'));
            $group->touch();
        }

        return back();
    }

    /**
     * Remove a group from this intake.
     *
     * A group serving more than one intake is only detached here — deleting the
     * دفعة ٤٦ و٤٧ programme groups off one batch would take them away from the
     * other, which is never what "remove it from this list" means. The row is
     * deleted outright once no intake is left holding it.
     */
    public function destroy(Cohort $cohort, StudentGroup $group): RedirectResponse
    {
        if ($group->cohorts()->count() > 1) {
            $group->cohorts()->detach($cohort->id);
            $group->touch();

            return back();
        }

        $group->delete();

        return back();
    }

    /**
     * Persist a new group order within one intake.
     *
     * Deliberately not Spatie's `setNewOrder()`: that bypasses model events and
     * would leave the public cache holding the old order.
     */
    public function reorder(ReorderStudentGroupsRequest $request, Cohort $cohort): RedirectResponse
    {
        $ids = $request->validated('ids');
        $groups = $cohort->groups()->whereIn('student_groups.id', $ids)->get()->keyBy('id');

        foreach ($ids as $index => $id) {
            $groups[$id]->update(['order' => $index + 1]);
        }

        return back();
    }

    /**
     * The intakes a new group serves: whatever was ticked, always including the
     * one it is being created in.
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, int>
     */
    private function cohortIdsFrom(array $validated, Cohort $cohort): array
    {
        $ids = array_map('intval', $validated['cohort_ids'] ?? []);

        return array_values(array_unique([...$ids, $cohort->id]));
    }

    /**
     * An empty programme or branch means "all of them", which the column stores
     * as null rather than as an empty string.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributesFrom(array $validated): array
    {
        $attributes = array_intersect_key($validated, array_flip(['major', 'branch', 'is_active']));

        foreach (['major', 'branch'] as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] === '') {
                $attributes[$field] = null;
            }
        }

        return $attributes;
    }
}
