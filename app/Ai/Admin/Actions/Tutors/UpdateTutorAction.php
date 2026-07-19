<?php

namespace App\Ai\Admin\Actions\Tutors;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Http\Requests\Manage\UpdatePrivateTutorRequest;
use App\Models\PrivateTutor\PrivateTutor;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Update a private tutor and sync its attached courses. Mirrors
 * {@see \App\Http\Controllers\Manage\PrivateTutorController::update()}, reusing
 * {@see UpdatePrivateTutorRequest} for validation. `url` is only replaced when
 * provided and `course_ids` is only synced when provided, matching the panel.
 */
class UpdateTutorAction extends AdminAction
{
    public function name(): string
    {
        return 'update_tutor';
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
        return 'Update an existing private tutor (تعديل بيانات مدرّس خصوصي). '
            .'Requires tutor_id (from list_tutors); name is replaced, url — when provided — is replaced '
            .'(send empty to clear), and course_ids — when provided — replace the tutor\'s attached courses.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $tutor = PrivateTutor::query()->find((int) ($input['tutor_id'] ?? 0));

        if ($tutor === null) {
            throw new AdminActionException('لا يوجد مدرّس خصوصي بهذا المعرّف. استخدم list_tutors للتأكد.');
        }

        $request = new UpdatePrivateTutorRequest;

        $validator = Validator::make($input, $request->rules(), $request->messages());

        if ($validator->fails()) {
            throw new AdminActionException($validator->errors()->first());
        }

        $data = $validator->validated();

        return [
            'tutor_id' => $tutor->id,
            'tutor_name' => $tutor->name,
            'name' => $data['name'],
            'has_url' => array_key_exists('url', $input),
            'url' => $data['url'] ?? null,
            'has_courses' => array_key_exists('course_ids', $input),
            'course_ids' => $data['course_ids'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        $summary = 'تعديل بيانات المدرّس الخصوصي «'.$normalized['tutor_name'].'»';

        if ($normalized['has_courses']) {
            $summary .= ' وربطه بـ'.count($normalized['course_ids']).' مقرراً';
        }

        return $summary.'.';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $tutor = PrivateTutor::query()->find((int) $normalized['tutor_id']);

        if ($tutor === null) {
            throw new AdminActionException('المدرّس المستهدف لم يعد موجوداً.');
        }

        $attributes = ['name' => $normalized['name']];

        if ($normalized['has_url']) {
            $attributes['url'] = $normalized['url'];
        }

        $tutor->update($attributes);

        if ($normalized['has_courses']) {
            $tutor->courses()->sync($normalized['course_ids']);
        }

        return ActionResult::text('تم تحديث بيانات المدرّس «'.$tutor->name.'».');
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
