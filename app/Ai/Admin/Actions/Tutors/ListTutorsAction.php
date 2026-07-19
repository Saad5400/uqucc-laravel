<?php

namespace App\Ai\Admin\Actions\Tutors;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\PrivateTutor\PrivateTutor;
use App\Models\PrivateTutor\PrivateTutorCourse;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The private tutors and the course taxonomy, with their ids — the lookup every
 * tutor and course write action needs first. Read-only. Unifies the old MCP
 * `list_tutors` into one action on both surfaces, additionally exposing each
 * tutor's `order` and each course's `tutors_count` (as the /manage tutors panel
 * shows them).
 */
class ListTutorsAction extends AdminAction
{
    public function name(): string
    {
        return 'list_tutors';
    }

    public function requiredAbility(): ?string
    {
        return 'manage-private-tutors';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'tutors';
    }

    public function description(): string
    {
        return 'List all private tutors and the available courses with their ids '
            .'(قائمة المدرّسين الخصوصيين والمقررات مع المعرّفات وترتيبها). '
            .'Each tutor includes its id, name, url, order and attached courses; each course includes its id, name, '
            .'order and tutors_count. Use the course ids here as course_ids when creating or updating a tutor, '
            .'and the ids for the reorder, update and delete actions. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $tutors = PrivateTutor::query()
            ->with(['courses' => fn ($query) => $query->orderBy('order')])
            ->orderBy('order')
            ->get()
            ->map(fn (PrivateTutor $tutor): array => [
                'id' => $tutor->id,
                'name' => $tutor->name,
                'url' => $tutor->url,
                'order' => $tutor->order,
                'courses' => $tutor->courses
                    ->map(fn (PrivateTutorCourse $course): array => [
                        'id' => $course->id,
                        'name' => $course->name,
                    ])
                    ->values(),
            ]);

        $courses = PrivateTutorCourse::query()
            ->withCount('tutors')
            ->orderBy('order')
            ->get()
            ->map(fn (PrivateTutorCourse $course): array => [
                'id' => $course->id,
                'name' => $course->name,
                'order' => $course->order,
                'tutors_count' => $course->tutors_count,
            ]);

        return ActionResult::text((string) json_encode([
            'tutors' => $tutors,
            'courses' => $courses,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
