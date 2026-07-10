<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    /**
     * Read-only activity feed: latest first, 25 per page, filterable by
     * log name, event, and subject type (as class basenames).
     */
    public function index(Request $request): Response
    {
        $subjectTypes = Activity::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type');

        $activities = Activity::query()
            ->with(['causer', 'subject' => function (MorphTo $morphTo) {
                $morphTo->constrain([
                    Page::class => fn (Builder $query) => $query->withTrashed(),
                ]);
            }])
            ->when($request->filled('log_name'), fn (Builder $query) => $query->where('log_name', $request->string('log_name')->toString()))
            ->when($request->filled('event'), fn (Builder $query) => $query->where('event', $request->string('event')->toString()))
            ->when($request->filled('subject_type'), function (Builder $query) use ($request, $subjectTypes) {
                $basename = $request->string('subject_type')->toString();

                $query->whereIn(
                    'subject_type',
                    $subjectTypes->filter(fn (string $type): bool => class_basename($type) === $basename)->values(),
                );
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Activity $activity): array => $this->mapActivity($activity));

        return Inertia::render('manage/activity/Index', [
            'activities' => $activities,
            'filters' => [
                'log_name' => $request->query('log_name'),
                'event' => $request->query('event'),
                'subject_type' => $request->query('subject_type'),
            ],
            'filterOptions' => [
                'logNames' => Activity::query()->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name'),
                'events' => Activity::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event'),
                'subjectTypes' => $subjectTypes->map(fn (string $type): string => class_basename($type))->unique()->sort()->values(),
            ],
        ]);
    }

    /**
     * @return array{
     *     id: int,
     *     log_name: string|null,
     *     description: string,
     *     event: string|null,
     *     subject_type: string|null,
     *     subject_id: int|null,
     *     subject_title: string|null,
     *     causer_name: string|null,
     *     created_at: string|null,
     *     created_at_human: string|null,
     *     changes: array{old: array<string, mixed>|null, new: array<string, mixed>|null}|null,
     * }
     */
    private function mapActivity(Activity $activity): array
    {
        $oldValues = $activity->properties?->get('old');
        $newValues = $activity->properties?->get('attributes');

        return [
            'id' => $activity->id,
            'log_name' => $activity->log_name,
            'description' => $activity->description,
            'event' => $activity->event,
            'subject_type' => $activity->subject_type ? class_basename($activity->subject_type) : null,
            'subject_id' => $activity->subject_id,
            'subject_title' => $this->subjectTitle($activity),
            'causer_name' => $activity->causer?->getAttribute('name'),
            'created_at' => $activity->created_at?->toISOString(),
            'created_at_human' => $activity->created_at?->locale('ar')->diffForHumans(),
            'changes' => (empty($oldValues) && empty($newValues)) ? null : [
                'old' => $oldValues ?: null,
                'new' => $newValues ?: null,
            ],
        ];
    }

    /**
     * A human label for the subject when it exposes one (Page titles,
     * User/PrivateTutor names, …); null for deleted or label-less subjects.
     */
    private function subjectTitle(Activity $activity): ?string
    {
        $subject = $activity->subject;

        if ($subject === null) {
            return null;
        }

        $label = $subject->getAttribute('title') ?? $subject->getAttribute('name');

        return is_scalar($label) ? (string) $label : null;
    }
}
