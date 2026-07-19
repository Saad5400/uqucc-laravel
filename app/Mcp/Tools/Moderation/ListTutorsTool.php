<?php

namespace App\Mcp\Tools\Moderation;

use App\Models\PrivateTutor\PrivateTutor;
use App\Models\PrivateTutor\PrivateTutorCourse;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The private tutors and courses, with their ids — the lookup a client needs
 * before create_tutor / update_tutor / delete_tutor.
 */
#[IsReadOnly]
#[Description('List all private tutors and the available courses with their ids (قائمة المدرّسين الخصوصيين والمقررات مع المعرّفات). Use the course ids here as course_ids when creating or updating a tutor.')]
class ListTutorsTool extends ModerationTool
{
    protected string $name = 'list_tutors';

    protected function requiredAbility(): string
    {
        return 'manage-private-tutors';
    }

    protected function perform(Request $request, User $user): Response
    {
        $tutors = PrivateTutor::query()
            ->with(['courses' => fn ($query) => $query->orderBy('order')])
            ->orderBy('order')
            ->get()
            ->map(fn (PrivateTutor $tutor): array => [
                'id' => $tutor->id,
                'name' => $tutor->name,
                'url' => $tutor->url,
                'courses' => $tutor->courses
                    ->map(fn (PrivateTutorCourse $course): array => [
                        'id' => $course->id,
                        'name' => $course->name,
                    ])
                    ->values(),
            ]);

        $courses = PrivateTutorCourse::query()
            ->orderBy('order')
            ->get(['id', 'name'])
            ->map(fn (PrivateTutorCourse $course): array => [
                'id' => $course->id,
                'name' => $course->name,
            ]);

        return Response::text((string) json_encode([
            'tutors' => $tutors,
            'courses' => $courses,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
