<?php

namespace App\Ai\Admin\Actions\Tutors;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\PrivateTutor\PrivateTutor;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Delete a private tutor. Attached courses are detached, not deleted. Mirrors
 * {@see \App\Http\Controllers\Manage\PrivateTutorController::destroy()}. Unifies
 * the old MCP `delete_tutor` into one action on both surfaces.
 */
class DeleteTutorAction extends AdminAction
{
    public function name(): string
    {
        return 'delete_tutor';
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
        return 'Delete a private tutor (حذف مدرّس خصوصي). '
            .'Requires tutor_id (from list_tutors). The tutor\'s courses are kept — only the tutor and its course links are removed. This cannot be undone.';
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

        return [
            'tutor_id' => $tutor->id,
            'tutor_name' => $tutor->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'حذف المدرّس الخصوصي «'.$normalized['tutor_name'].'» نهائياً.';
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

        $name = $tutor->name;
        $tutor->delete();

        return ActionResult::text('تم حذف المدرّس «'.$name.'».');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tutor_id' => $schema->integer()
                ->description('The id of the tutor to delete, from list_tutors.')
                ->required(),
        ];
    }
}
