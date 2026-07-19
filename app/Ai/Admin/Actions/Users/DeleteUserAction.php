<?php

namespace App\Ai\Admin\Actions\Users;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Delete a panel user. Mirrors
 * {@see \App\Http\Controllers\Manage\UserController::destroy()} and the MCP
 * `delete_user` tool: self-deletion is blocked and authored pages survive (only
 * the author pivot rows are removed). This cannot be undone.
 */
class DeleteUserAction extends AdminAction
{
    public function name(): string
    {
        return 'delete_user';
    }

    public function requiredAbility(): ?string
    {
        return 'manage-users';
    }

    public function category(): string
    {
        return 'users';
    }

    public function description(): string
    {
        return 'Delete a panel user (حذف مستخدم من اللوحة). '
            .'Requires user_id (from list_users). You cannot delete your own account. Pages the user authored are kept. '
            .'This cannot be undone.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $target = User::query()->find((int) ($input['user_id'] ?? 0));

        if ($target === null) {
            throw new AdminActionException('لم يُعثر على المستخدم المطلوب. استخدم list_users للتأكد.');
        }

        if ($user->is($target)) {
            throw new AdminActionException('لا يمكنك حذف حسابك.');
        }

        return ['user_id' => $target->id, 'user_name' => $target->name];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'حذف المستخدم «'.$normalized['user_name'].'» نهائياً من اللوحة.';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $target = User::query()->find((int) $normalized['user_id']);

        if ($target === null) {
            throw new AdminActionException('المستخدم المستهدف لم يعد موجوداً.');
        }

        if ($user->is($target)) {
            throw new AdminActionException('لا يمكنك حذف حسابك.');
        }

        $name = $target->name;
        $target->delete();

        return ActionResult::text('تم حذف المستخدم «'.$name.'».');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()
                ->description('The id of the user to delete, from list_users.')
                ->required(),
        ];
    }
}
