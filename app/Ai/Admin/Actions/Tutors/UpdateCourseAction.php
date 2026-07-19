<?php

namespace App\Ai\Admin\Actions\Tutors;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Http\Requests\Manage\UpdatePrivateTutorCourseRequest;
use App\Models\PrivateTutor\PrivateTutorCourse;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Rename a private-tutor course. Mirrors
 * {@see \App\Http\Controllers\Manage\PrivateTutorCourseController::update()},
 * reusing {@see UpdatePrivateTutorCourseRequest} for validation. New capability:
 * the MCP server could not manage courses at all.
 */
class UpdateCourseAction extends AdminAction
{
    public function name(): string
    {
        return 'update_course';
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
        return 'Rename a private-tutor course (تعديل اسم مقرر للمدرّسين الخصوصيين). '
            .'Requires course_id (from list_tutors) and the new name.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $course = PrivateTutorCourse::query()->find((int) ($input['course_id'] ?? 0));

        if ($course === null) {
            throw new AdminActionException('لا يوجد مقرر بهذا المعرّف. استخدم list_tutors للتأكد.');
        }

        $request = new UpdatePrivateTutorCourseRequest;

        $validator = Validator::make($input, $request->rules(), $request->messages());

        if ($validator->fails()) {
            throw new AdminActionException($validator->errors()->first());
        }

        return [
            'course_id' => $course->id,
            'old_name' => $course->name,
            'name' => $validator->validated()['name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'تعديل اسم المقرر «'.$normalized['old_name'].'» إلى «'.$normalized['name'].'».';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $course = PrivateTutorCourse::query()->find((int) $normalized['course_id']);

        if ($course === null) {
            throw new AdminActionException('المقرر المستهدف لم يعد موجوداً.');
        }

        $course->update(['name' => $normalized['name']]);

        return ActionResult::text('تم تحديث اسم المقرر إلى «'.$course->name.'».');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'course_id' => $schema->integer()
                ->description('The id of the course to rename, from list_tutors.')
                ->required(),
            'name' => $schema->string()
                ->description('The new course name.')
                ->required(),
        ];
    }
}
