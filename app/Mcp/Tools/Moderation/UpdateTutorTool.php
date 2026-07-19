<?php

namespace App\Mcp\Tools\Moderation;

use App\Http\Requests\Manage\UpdatePrivateTutorRequest;
use App\Models\PrivateTutor\PrivateTutor;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * Update a private tutor and sync its attached courses. Mirrors
 * {@see \App\Http\Controllers\Manage\PrivateTutorController::update()},
 * reusing {@see UpdatePrivateTutorRequest} for validation. `course_ids` is
 * only synced when provided, matching the panel.
 */
#[Description('Update an existing private tutor (تعديل بيانات مدرّس خصوصي). Requires tutor_id (from list_tutors); name and url are replaced, and course_ids — when provided — replace the tutor\'s attached courses.')]
class UpdateTutorTool extends ModerationTool
{
    protected string $name = 'update_tutor';

    protected function requiredAbility(): string
    {
        return 'manage-private-tutors';
    }

    protected function perform(Request $request, User $user): Response
    {
        $arguments = $request->all();

        $tutor = PrivateTutor::query()->find((int) ($arguments['tutor_id'] ?? 0));

        if ($tutor === null) {
            return Response::error('لم يُعثر على المدرّس المطلوب. No tutor found for that id.');
        }

        $rules = (new UpdatePrivateTutorRequest)->rules();
        $messages = (new UpdatePrivateTutorRequest)->messages();

        $data = $this->validateInput($request, $rules, $messages);

        if ($data instanceof Response) {
            return $data;
        }

        $attributes = ['name' => $data['name']];

        if (array_key_exists('url', $arguments)) {
            $attributes['url'] = $data['url'] ?? null;
        }

        $tutor->update($attributes);

        if (array_key_exists('course_ids', $arguments)) {
            $tutor->courses()->sync($data['course_ids'] ?? []);
        }

        return Response::text('تم تحديث بيانات المدرّس «'.$tutor->name.'». Tutor updated.');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tutor_id' => $schema->integer()
                ->description('The id of the tutor to update, from list_tutors.')
                ->required(),
            'name' => $schema->string()
                ->description('The tutor\'s name.')
                ->required(),
            'url' => $schema->string()
                ->description('Optional link to the tutor. Must be a valid URL. Send empty to clear.'),
            'course_ids' => $schema->array()
                ->description('Optional ids of the courses this tutor teaches (replaces the current set), from list_tutors.')
                ->items($schema->integer()),
        ];
    }
}
