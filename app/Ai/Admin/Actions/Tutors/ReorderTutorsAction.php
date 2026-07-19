<?php

namespace App\Ai\Admin\Actions\Tutors;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Http\Requests\Manage\ReorderPrivateTutorsRequest;
use App\Models\PrivateTutor\PrivateTutor;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Reorder the private tutors from an ordered array of ids. Mirrors
 * {@see \App\Http\Controllers\Manage\PrivateTutorController::reorder()}, reusing
 * {@see ReorderPrivateTutorsRequest} for validation. Deliberately not Spatie's
 * `setNewOrder()`: each dirty model is saved individually so the cache flush in
 * `PrivateTutor::booted()` keeps firing (frozen cache-invalidation contract).
 */
class ReorderTutorsAction extends AdminAction
{
    public function name(): string
    {
        return 'reorder_tutors';
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
        return 'Reorder the private tutors (إعادة ترتيب المدرّسين الخصوصيين). '
            .'Provide ids: the full list of tutor ids (from list_tutors) in the desired display order. '
            .'Each tutor is assigned a sequential order starting at 1.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $request = new ReorderPrivateTutorsRequest;

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
        return 'إعادة ترتيب '.count($normalized['ids']).' مدرّساً خصوصياً.';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $ids = $normalized['ids'];
        $tutors = PrivateTutor::query()->findMany($ids)->keyBy('id');

        foreach ($ids as $index => $id) {
            $tutors[$id]->update(['order' => $index + 1]);
        }

        return ActionResult::text('تم إعادة ترتيب المدرّسين الخصوصيين.');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ids' => $schema->array()
                ->description('The tutor ids (from list_tutors) in the desired display order.')
                ->items($schema->integer())
                ->required(),
        ];
    }
}
