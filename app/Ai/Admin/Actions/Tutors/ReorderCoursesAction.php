<?php

namespace App\Ai\Admin\Actions\Tutors;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Http\Requests\Manage\ReorderPrivateTutorCoursesRequest;
use App\Models\PrivateTutor\PrivateTutorCourse;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Reorder the private-tutor courses from an ordered array of ids. Mirrors
 * {@see \App\Http\Controllers\Manage\PrivateTutorCourseController::reorder()},
 * reusing {@see ReorderPrivateTutorCoursesRequest} for validation. Deliberately
 * not Spatie's `setNewOrder()`: each dirty model is saved individually so the
 * cache flush in `PrivateTutorCourse::booted()` keeps firing (frozen
 * cache-invalidation contract). New capability: the MCP server could not manage
 * courses at all.
 */
class ReorderCoursesAction extends AdminAction
{
    public function name(): string
    {
        return 'reorder_courses';
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
        return 'Reorder the private-tutor courses (إعادة ترتيب مقررات المدرّسين الخصوصيين). '
            .'Provide ids: the full list of course ids (from list_tutors) in the desired display order. '
            .'Each course is assigned a sequential order starting at 1.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $request = new ReorderPrivateTutorCoursesRequest;

        $validator = Validator::make($input, $request->rules(), $request->messages());

        if ($validator->fails()) {
            throw new AdminActionException($validator->errors()->first());
        }

        return [
            'ids' => array_map('intval', $validator->validated()['ids']),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'إعادة ترتيب '.count($normalized['ids']).' مقرراً للمدرّسين الخصوصيين.';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $ids = $normalized['ids'];
        $courses = PrivateTutorCourse::query()->findMany($ids)->keyBy('id');

        foreach ($ids as $index => $id) {
            $courses[$id]->update(['order' => $index + 1]);
        }

        return ActionResult::text('تم إعادة ترتيب مقررات المدرّسين الخصوصيين.');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ids' => $schema->array()
                ->description('The course ids (from list_tutors) in the desired display order.')
                ->items($schema->integer())
                ->required(),
        ];
    }
}
