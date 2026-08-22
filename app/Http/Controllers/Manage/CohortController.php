<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\ReorderCohortsRequest;
use App\Http\Requests\Manage\StoreCohortRequest;
use App\Http\Requests\Manage\UpdateCohortRequest;
use App\Models\StudentGroup\Branch;
use App\Models\StudentGroup\Cohort;
use App\Models\StudentGroup\GroupSupervisor;
use App\Models\StudentGroup\Major;
use App\Models\StudentGroup\StudentGroup;
use App\Models\StudentGroup\SupervisorSection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CohortController extends Controller
{
    /**
     * List the intakes, each with the numbers that decide whether it is usable
     * right now: how many groups it has, and how many supervisors are still in
     * the rotation across them.
     */
    public function index(): Response
    {
        return Inertia::render('manage/groups/Index', [
            'cohorts' => Cohort::query()
                ->with(['groups' => fn ($query) => $query->withCount([
                    'supervisors',
                    'supervisors as available_supervisors_count' => fn ($inner) => $inner->where('is_available', true),
                ])])
                ->orderBy('order')
                ->get()
                ->map(fn (Cohort $cohort) => $this->summarize($cohort)),
        ]);
    }

    /**
     * The intake workspace: its settings, its groups, and every supervisor in
     * them. Supervisors ship flat with their section on each row — the client
     * buckets them, which keeps a group that only fills one section from
     * rendering two empty ones.
     */
    public function show(Cohort $cohort): Response
    {
        $groups = $cohort->groups()
            ->with(['supervisors' => fn ($query) => $query->orderBy('order'), 'cohorts'])
            ->withCount([
                'supervisors',
                'supervisors as available_supervisors_count' => fn ($query) => $query->where('is_available', true),
            ])
            ->orderBy('student_groups.order')
            ->get();

        $cohort->setRelation('groups', $groups);

        return Inertia::render('manage/groups/Show', [
            'cohort' => $this->summarize($cohort),
            'groups' => $groups->map(fn (StudentGroup $group) => [
                'id' => $group->id,
                'name' => $group->displayName(),
                'is_general' => $group->isGeneral(),
                'major' => $group->major?->value,
                'branch' => $group->branch?->value,
                'branch_label' => $group->branch?->label() ?? 'كل الفروع',
                'is_active' => $group->is_active,
                // Which other intakes would keep this group if it were removed here.
                'shared_with' => $group->cohorts
                    ->reject(fn (Cohort $other) => $other->id === $cohort->id)
                    ->map(fn (Cohort $other) => ['id' => $other->id, 'name' => $other->name])
                    ->values(),
                'supervisors' => $group->supervisors->map(fn (GroupSupervisor $supervisor) => [
                    'id' => $supervisor->id,
                    'name' => $supervisor->name,
                    'telegram_username' => $supervisor->telegram_username,
                    'whatsapp_number' => $supervisor->whatsappDisplay(),
                    'contacts' => $supervisor->contacts(),
                    'section' => $supervisor->section->value,
                    'is_available' => $supervisor->is_available,
                ])->values(),
            ]),
            'taxonomy' => [
                'majors' => array_map(
                    fn (Major $major) => ['value' => $major->value, 'label' => $major->label()],
                    Major::ordered(),
                ),
                'branches' => array_map(
                    fn (Branch $branch) => ['value' => $branch->value, 'label' => $branch->label()],
                    Branch::ordered(),
                ),
                'sections' => array_map(
                    fn (SupervisorSection $section) => ['value' => $section->value, 'label' => $section->label()],
                    SupervisorSection::ordered(),
                ),
                'cohorts' => Cohort::query()->orderBy('order')->get()
                    ->map(fn (Cohort $option) => ['value' => (string) $option->id, 'label' => $option->name])
                    ->values(),
            ],
        ]);
    }

    /**
     * Create an intake.
     */
    public function store(StoreCohortRequest $request): RedirectResponse
    {
        Cohort::create($this->attributesFrom($request->validated()));

        return back();
    }

    /**
     * Update an intake's settings.
     */
    public function update(UpdateCohortRequest $request, Cohort $cohort): RedirectResponse
    {
        $cohort->update($this->attributesFrom($request->validated()));

        return back();
    }

    /**
     * Delete an intake along with its groups and their supervisors.
     */
    public function destroy(Cohort $cohort): RedirectResponse
    {
        $cohort->delete();

        return to_route('manage.cohorts.index');
    }

    /**
     * Persist a new intake order from an ordered array of ids.
     *
     * Deliberately not Spatie's `setNewOrder()`: that runs bulk query-builder
     * updates which bypass model events, so the cache flush in
     * `Cohort::booted()` would never fire and the public page would keep
     * serving the old order.
     */
    public function reorder(ReorderCohortsRequest $request): RedirectResponse
    {
        $ids = $request->validated('ids');
        $cohorts = Cohort::query()->findMany($ids)->keyBy('id');

        foreach ($ids as $index => $id) {
            $cohorts[$id]->update(['order' => $index + 1]);
        }

        return back();
    }

    /**
     * The fields every intake view shares — the list needs the counts to show
     * whether an intake is usable, and both the list and the workspace open the
     * same edit dialog, which needs the full settings.
     *
     * Supervisor totals are summed from the loaded groups rather than a
     * `withCount`: groups reach an intake through a pivot, so there is no single
     * relation to count through.
     *
     * @return array<string, mixed>
     */
    private function summarize(Cohort $cohort): array
    {
        $groups = $cohort->relationLoaded('groups') ? $cohort->groups : collect();

        return [
            'id' => $cohort->id,
            'name' => $cohort->name,
            'description' => $cohort->description,
            'note' => $cohort->note,
            'requirements' => array_values($cohort->requirements ?? []),
            'is_active' => $cohort->is_active,
            'is_featured' => $cohort->is_featured,
            'groups_count' => $groups->count(),
            'supervisors_count' => (int) $groups->sum('supervisors_count'),
            'available_supervisors_count' => (int) $groups->sum('available_supervisors_count'),
        ];
    }

    /**
     * Normalize the writable attributes: an empty checklist is stored as null
     * so "no requirements" has exactly one representation.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributesFrom(array $validated): array
    {
        $attributes = array_intersect_key($validated, array_flip(['name', 'description', 'note', 'is_active', 'is_featured']));

        if (array_key_exists('requirements', $validated)) {
            $requirements = array_values(array_filter(
                array_map('trim', $validated['requirements'] ?? []),
                fn (string $requirement) => $requirement !== '',
            ));

            $attributes['requirements'] = $requirements === [] ? null : $requirements;
        }

        return $attributes;
    }
}
