<?php

namespace App\Mcp\Tools\Moderation;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * Delete a panel user. Self-deletion is blocked and authored pages survive
 * (only the author pivot rows are removed). Mirrors
 * {@see \App\Http\Controllers\Manage\UserController::destroy()}.
 */
#[Description('Delete a panel user (حذف مستخدم من اللوحة). Requires user_id (from list_users). You cannot delete your own account. Pages the user authored are kept. This cannot be undone.')]
class DeleteUserTool extends ModerationTool
{
    protected string $name = 'delete_user';

    protected function requiredAbility(): string
    {
        return 'manage-users';
    }

    protected function perform(Request $request, User $user): Response
    {
        $target = User::query()->find((int) $request->get('user_id'));

        if ($target === null) {
            return Response::error('لم يُعثر على المستخدم المطلوب. No user found for that id.');
        }

        if ($user->is($target)) {
            return Response::error('لا يمكنك حذف حسابك. You cannot delete your own account.');
        }

        $name = $target->name;
        $target->delete();

        return Response::text('تم حذف المستخدم «'.$name.'». User deleted.');
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
