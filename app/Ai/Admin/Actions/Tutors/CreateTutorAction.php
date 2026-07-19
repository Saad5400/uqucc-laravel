<?php

namespace App\Ai\Admin\Actions\Tutors;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Http\Requests\Manage\StorePrivateTutorRequest;
use App\Models\PrivateTutor\PrivateTutor;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Create a private tutor and attach its courses. Mirrors
 * {@see \App\Http\Controllers\Manage\PrivateTutorController::store()}, reusing
 * {@see StorePrivateTutorRequest} for validation. Unifies the old MCP
 * `create_tutor` into one action on both surfaces.
 */
class CreateTutorAction extends AdminAction
{
    public function name(): string
    {
        return 'create_tutor';
    }

    public function requiredAbility(): ?string
    {
        return 'manage-private-tutors';
    }

    public function category(): string
    {
        return 'tutors';
    }

    public function description(): string
    {
        return 'Create a new private tutor (إضافة مدرّس خصوصي جديد). '
            .'Provide the name, an optional URL, and optional course_ids (from list_tutors) to attach the courses the tutor teaches.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $request = new StorePrivateTutorRequest;

        $validator = Validator::make($input, $request->rules(), $request->messages());

        if ($validator->fails()) {
            throw new AdminActionException($validator->errors()->first());
        }

        $data = $validator->validated();

        return [
            'name' => $data['name'],
            'url' => $data['url'] ?? null,
            'has_courses' => array_key_exists('course_ids', $data),
            'course_ids' => $data['course_ids'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        $summary = 'إضافة مدرّس خصوصي «'.$normalized['name'].'»';

        if ($normalized['has_courses']) {
            $summary .= ' مع ربط '.count($normalized['course_ids']).' مقرراً';
        }

        return $summary.'.';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $tutor = PrivateTutor::create(array_filter(
            [
                'name' => $normalized['name'],
                'url' => $normalized['url'],
            ],
            fn (mixed $value): bool => $value !== null,
        ));

        if ($normalized['has_courses']) {
            $tutor->courses()->sync($normalized['course_ids']);
        }

        return ActionResult::text('تم إنشاء المدرّس «'.$tutor->name.'» (id: '.$tutor->id.').');
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
