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
        $cohort->groups()->create($this->attributesFrom($request->validated()));

        return back();
    }

    /**
     * Update a group. Accepts partial payloads so the visibility switch in the
     * group list can send `is_active` on its own.
     */
    public function update(UpdateStudentGroupRequest $request, StudentGroup $group): RedirectResponse
    {
        $group->update($this->attributesFrom($request->validated()));

        return back();
    }

    /**
     * Delete a group along with its supervisors.
     */
    public function destroy(StudentGroup $group): RedirectResponse
    {
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
        $groups = $cohort->groups()->findMany($ids)->keyBy('id');

        foreach ($ids as $index => $id) {
            $groups[$id]->update(['order' => $index + 1]);
        }

        return back();
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
