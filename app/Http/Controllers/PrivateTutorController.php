<?php

namespace App\Http\Controllers;

use App\Models\PrivateTutor\PrivateTutor;
use App\Models\PrivateTutor\PrivateTutorCourse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class PrivateTutorController extends Controller
{
    /**
     * Display the private tutors page
     */
    public function index(): Response
    {
        $data = $this->getCachedData();

        return Inertia::render('tools/PrivateTutorsPage', [
            'courses' => $data['courses'],
            'tutors' => $data['tutors'],
        ]);
    }

    /**
     * Get cached tutors and courses data
     */
    private function getCachedData(): array
    {
        return Cache::remember(
            'private_tutors_data',
            config('app-cache.pages.ttl', 1800),
            function () {
                $courses = PrivateTutorCourse::query()
                    ->with(['tutors' => fn ($q) => $q->orderBy('order')])
                    ->orderBy('order')
                    ->get()
                    ->map(fn ($course) => [
                        'id' => $course->id,
                        'name' => $course->name,
                        'tutors' => $course->tutors->map(fn ($tutor) => [
                            'id' => $tutor->id,
                            'name' => $tutor->name,
                            'url' => $tutor->url,
                        ])->values()->toArray(),
                    ])
                    ->toArray();

                $tutors = PrivateTutor::query()
                    ->with(['courses' => fn ($q) => $q->orderBy('order')])
                    ->orderBy('order')
                    ->get()
                    ->map(fn ($tutor) => [
                        'id' => $tutor->id,
                        'name' => $tutor->name,
                        'url' => $tutor->url,
                        'courses' => $tutor->courses->map(fn ($course) => [
                            'id' => $course->id,
                            'name' => $course->name,
                        ])->values()->toArray(),
                    ])
                    ->toArray();

                return [
                    'courses' => $courses,
                    'tutors' => $tutors,
                ];
            }
        );
    }
}
