<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\ReorderPrivateTutorCoursesRequest;
use App\Http\Requests\Manage\StorePrivateTutorCourseRequest;
use App\Http\Requests\Manage\UpdatePrivateTutorCourseRequest;
use App\Models\PrivateTutor\PrivateTutorCourse;
use Illuminate\Http\RedirectResponse;

class PrivateTutorCourseController extends Controller
{
    /**
     * Create a new course. Also used for inline creation from the tutor edit
     * dialog: the redirect back refreshes the page props, and the dialog picks
     * the new course out of the updated course list.
     */
    public function store(StorePrivateTutorCourseRequest $request): RedirectResponse
    {
        PrivateTutorCourse::create($request->safe()->only(['name']));

        return back();
    }

    /**
     * Rename a course.
     */
    public function update(UpdatePrivateTutorCourseRequest $request, PrivateTutorCourse $course): RedirectResponse
    {
        $course->update($request->safe()->only(['name']));

        return back();
    }

    /**
     * Delete a course (it is detached from tutors; tutors are kept).
     */
    public function destroy(PrivateTutorCourse $course): RedirectResponse
    {
        $course->delete();

        return back();
    }

    /**
     * Persist a new course order from an ordered array of ids.
     *
     * Deliberately not Spatie's `setNewOrder()`: that runs bulk query-builder
     * updates which bypass model events, so the cache flush in
     * `PrivateTutorCourse::booted()` would never fire. Saving each dirty model
     * keeps the frozen cache-invalidation contract intact.
     */
    public function reorder(ReorderPrivateTutorCoursesRequest $request): RedirectResponse
    {
        $ids = $request->validated('ids');
        $courses = PrivateTutorCourse::query()->findMany($ids)->keyBy('id');

        foreach ($ids as $index => $id) {
            $courses[$id]->update(['order' => $index + 1]);
        }

        return back();
    }
}
