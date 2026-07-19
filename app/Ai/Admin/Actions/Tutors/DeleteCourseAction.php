<?php

namespace App\Ai\Admin\Actions\Tutors;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\PrivateTutor\PrivateTutorCourse;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Delete a private-tutor course. It is detached from any tutors via the pivot
 * cascade; the tutors themselves are kept. Mirrors
 * {@see \App\Http\Controllers\Manage\PrivateTutorCourseController::destroy()}.
 * New capability: the MCP server could not manage courses at all.
 */
class DeleteCourseAction extends AdminAction
{
    public function name(): string
    {
        return 'delete_course';
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
        return 'Delete a private-tutor course (حذف مقرر من مقررات المدرّسين الخصوصيين). '
            .'Requires course_id (from list_tutors). The course is detached from any tutors — the tutors are kept. This cannot be undone.';
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

        return [
            'course_id' => $course->id,
            'course_name' => $course->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'حذف المقرر «'.$normalized['course_name'].'» نهائياً (يُفصل عن المدرّسين المرتبطين به).';
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

        $name = $course->name;
        $course->delete();

        return ActionResult::text('تم حذف المقرر «'.$name.'».');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'course_id' => $schema->integer()
                ->description('The id of the course to delete, from list_tutors.')
                ->required(),
        ];
    }
}
