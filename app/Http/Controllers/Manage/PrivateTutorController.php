<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\ReorderPrivateTutorsRequest;
use App\Http\Requests\Manage\StorePrivateTutorRequest;
use App\Http\Requests\Manage\UpdatePrivateTutorRequest;
use App\Models\PrivateTutor\PrivateTutor;
use App\Models\PrivateTutor\PrivateTutorCourse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PrivateTutorController extends Controller
{
    /**
     * Show the tutors workspace (both the tutors and courses tabs share this page).
     */
    public function index(): Response
    {
        return Inertia::render('manage/tutors/Index', [
            'tutors' => PrivateTutor::query()
                ->with(['courses' => fn ($query) => $query->orderBy('order')])
                ->orderBy('order')
                ->get()
                ->map(fn (PrivateTutor $tutor) => [
                    'id' => $tutor->id,
                    'name' => $tutor->name,
                    'url' => $tutor->url,
                    'courses' => $tutor->courses
                        ->map(fn (PrivateTutorCourse $course) => [
                            'id' => $course->id,
                            'name' => $course->name,
                        ])
                        ->values(),
                ]),
            'courses' => PrivateTutorCourse::query()
                ->withCount('tutors')
                ->orderBy('order')
                ->get()
                ->map(fn (PrivateTutorCourse $course) => [
                    'id' => $course->id,
                    'name' => $course->name,
                    'tutors_count' => $course->tutors_count,
                ]),
        ]);
    }

    /**
     * Create a new tutor and attach its courses.
     */
    public function store(StorePrivateTutorRequest $request): RedirectResponse
    {
        $tutor = PrivateTutor::create($request->safe()->only(['name', 'url']));

        if ($request->has('course_ids')) {
            $tutor->courses()->sync($request->validated('course_ids', []));
        }

        return back();
    }

    /**
     * Update a tutor and sync its attached courses.
     */
    public function update(UpdatePrivateTutorRequest $request, PrivateTutor $tutor): RedirectResponse
    {
        $tutor->update($request->safe()->only(['name', 'url']));

        if ($request->has('course_ids')) {
            $tutor->courses()->sync($request->validated('course_ids', []));
        }

        return back();
    }

    /**
     * Delete a tutor (attached courses are detached, not deleted).
     */
    public function destroy(PrivateTutor $tutor): RedirectResponse
    {
        $tutor->delete();

        return back();
    }

    /**
     * Persist a new tutor order from an ordered array of ids.
     *
     * Deliberately not Spatie's `setNewOrder()`: that runs bulk query-builder
     * updates which bypass model events, so the cache flush in
     * `PrivateTutor::booted()` would never fire. Saving each dirty model keeps
     * the frozen cache-invalidation contract intact.
     */
    public function reorder(ReorderPrivateTutorsRequest $request): RedirectResponse
    {
        $ids = $request->validated('ids');
        $tutors = PrivateTutor::query()->findMany($ids)->keyBy('id');

        foreach ($ids as $index => $id) {
            $tutors[$id]->update(['order' => $index + 1]);
        }

        return back();
    }
}
