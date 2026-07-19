<?php

namespace App\Ai\Admin\Actions\Tutors;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Http\Requests\Manage\StorePrivateTutorCourseRequest;
use App\Models\PrivateTutor\PrivateTutorCourse;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Create a private-tutor course (the taxonomy tutors are tagged with). Mirrors
 * {@see \App\Http\Controllers\Manage\PrivateTutorCourseController::store()},
 * reusing {@see StorePrivateTutorCourseRequest} for validation. New capability:
 * the MCP server could not manage courses at all.
 */
class CreateCourseAction extends AdminAction
{
    public function name(): string
    {
        return 'create_course';
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
        return 'Create a new private-tutor course (إضافة مقرر جديد للمدرّسين الخصوصيين). '
            .'Provide the course name. Courses are the taxonomy tutors are tagged with — attach them to tutors via create_tutor / update_tutor.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $request = new StorePrivateTutorCourseRequest;

        $validator = Validator::make($input, $request->rules(), $request->messages());

        if ($validator->fails()) {
            throw new AdminActionException($validator->errors()->first());
        }

        return [
            'name' => $validator->validated()['name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'إضافة مقرر جديد «'.$normalized['name'].'» للمدرّسين الخصوصيين.';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $course = PrivateTutorCourse::create(['name' => $normalized['name']]);

        return ActionResult::text('تم إنشاء المقرر «'.$course->name.'» (id: '.$course->id.').');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('The course name.')
                ->required(),
        ];
    }
}
