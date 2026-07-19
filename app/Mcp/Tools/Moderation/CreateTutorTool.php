<?php

namespace App\Mcp\Tools\Moderation;

use App\Http\Requests\Manage\StorePrivateTutorRequest;
use App\Models\PrivateTutor\PrivateTutor;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * Create a private tutor and attach its courses. Mirrors
 * {@see \App\Http\Controllers\Manage\PrivateTutorController::store()}, reusing
 * {@see StorePrivateTutorRequest} for validation.
 */
#[Description('Create a new private tutor (إضافة مدرّس خصوصي جديد). Provide the name, an optional URL, and optional course_ids (from list_tutors) to attach the courses the tutor teaches.')]
class CreateTutorTool extends ModerationTool
{
    protected string $name = 'create_tutor';

    protected function requiredAbility(): string
    {
        return 'manage-private-tutors';
    }

    protected function perform(Request $request, User $user): Response
    {
        $rules = (new StorePrivateTutorRequest)->rules();
        $messages = (new StorePrivateTutorRequest)->messages();

        $data = $this->validateInput($request, $rules, $messages);

        if ($data instanceof Response) {
            return $data;
        }

        $tutor = PrivateTutor::create(array_filter(
            [
                'name' => $data['name'],
                'url' => $data['url'] ?? null,
            ],
            fn (mixed $value): bool => $value !== null,
        ));

        if (array_key_exists('course_ids', $data)) {
            $tutor->courses()->sync($data['course_ids'] ?? []);
        }

        return Response::text('تم إنشاء المدرّس «'.$tutor->name.'» (id: '.$tutor->id.'). Tutor created.');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('The tutor\'s name.')
                ->required(),
            'url' => $schema->string()
                ->description('Optional link to the tutor (e.g. a WhatsApp/Telegram/X profile). Must be a valid URL.'),
            'course_ids' => $schema->array()
                ->description('Optional ids of the courses this tutor teaches, from list_tutors.')
                ->items($schema->integer()),
        ];
    }
}
