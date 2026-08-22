<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\StudentGroup\Cohort;
use App\Models\StudentGroup\GroupSupervisor;
use App\Models\StudentGroup\StudentGroup;
use App\Models\StudentGroup\SupervisorSection;
use App\Support\Seo;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class StudentGroupController extends Controller
{
    /** The content page backing this route, if an admin made one for the intro copy. */
    private const PAGE_SLUG = '/qroubat';

    /**
     * Display the student groups page.
     *
     * Unlisted by design: the route answers to anyone holding the link, but it
     * is kept out of the navigation, the sitemap and the search index, and asks
     * crawlers not to index it — the supervisor lists are for newcomers who
     * were pointed here, not for the open web.
     *
     * Which supervisor a visitor is shown is decided in the browser, not here:
     * this response is served from the full-page response cache, so a pick made
     * server-side would freeze onto one person for the life of the cache entry
     * and hand them every request — the exact pile-up the rotation prevents.
     */
    public function index(): Response
    {
        $page = Page::where('slug', self::PAGE_SLUG)->first();

        $seo = $page
            ? Seo::forPage($page)
            : Seo::forDefault(
                'قروبات الدفعات',
                'كيف تنضم إلى قروب دفعتك وتخصصك في كلية الحاسبات بجامعة أم القرى، ومع من تتواصل من المشرفين.'
            );

        return Inertia::render('StudentGroupsPage', [
            'cohorts' => $this->getCachedCohorts(),
            'page' => $page ? [
                'html_content' => $page->html_content,
                'title' => $page->title,
            ] : null,
            'hasContent' => $page && ! empty($page->html_content),
            'seo' => [...$seo, 'noindex' => true],
        ]);
    }

    /**
     * The public payload: active intakes, each with its active groups and the
     * supervisors currently in the rotation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCachedCohorts(): array
    {
        return Cache::remember(
            Cohort::CACHE_KEY,
            config('app-cache.pages.ttl', 1800),
            fn () => Cohort::query()
                ->where('is_active', true)
                ->with([
                    'groups' => fn ($query) => $query->where('is_active', true)->orderBy('student_groups.order'),
                    'groups.supervisors' => fn ($query) => $query->where('is_available', true)->orderBy('order'),
                ])
                ->orderByDesc('is_featured')
                ->orderBy('order')
                ->get()
                ->map(fn (Cohort $cohort) => [
                    'id' => $cohort->id,
                    'name' => $cohort->name,
                    'description' => $cohort->description,
                    'note' => $cohort->note,
                    'requirements' => array_values(array_filter($cohort->requirements ?? [])),
                    'is_featured' => $cohort->is_featured,
                    'groups' => $cohort->groups
                        ->map(fn (StudentGroup $group) => $this->presentGroup($group))
                        ->values()
                        ->toArray(),
                ])
                ->values()
                ->toArray()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGroup(StudentGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->displayName(),
            'is_general' => $group->isGeneral(),
            'major' => $group->major?->value,
            'branch' => $group->branch?->value,
            'branch_label' => $group->branch?->label() ?? 'كل الفروع',
            // Compact form for summaries and card subtitles, where the full
            // «الفرع الرئيسي — مكة المكرمة» wraps or truncates on a phone.
            'branch_short' => $group->branch?->shortLabel() ?? 'كل الفروع',
            'sections' => $this->sectionsFor($group),
            'supervisors_count' => $group->supervisors->count(),
        ];
    }

    /**
     * A group's supervisors bucketed by section, keeping only the sections that
     * actually have someone available — a group that fills one section renders
     * one column rather than an empty second one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sectionsFor(StudentGroup $group): array
    {
        $sections = [];

        foreach (SupervisorSection::ordered() as $section) {
            $supervisors = $group->supervisors
                ->where('section', $section)
                ->map(fn (GroupSupervisor $supervisor) => [
                    'id' => $supervisor->id,
                    'name' => $supervisor->name,
                    'contacts' => $supervisor->contacts(),
                ])
                ->values()
                ->toArray();

            if ($supervisors === []) {
                continue;
            }

            $sections[] = [
                'key' => $section->value,
                'label' => $section->label(),
                'supervisors' => $supervisors,
            ];
        }

        return $sections;
    }
}
