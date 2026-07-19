<?php

namespace App\Ai\Admin\Actions\Users;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Spatie\Permission\Models\Role;

/**
 * The panel users with their roles, review flag and ids, plus the assignable
 * role names — the lookup a surface needs before create_user / update_user /
 * delete_user. Read-only. Unifies the MCP `list_users` tool onto both surfaces.
 */
class ListUsersAction extends AdminAction
{
    public function name(): string
    {
        return 'list_users';
    }

    public function requiredAbility(): ?string
    {
        return 'manage-users';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'users';
    }

    public function description(): string
    {
        return 'List the panel users with their roles, review flag and ids, plus the assignable role names '
            .'(قائمة مستخدمي اللوحة وأدوارهم وحالة إلزام المراجعة والتوثيق ومعرفاتهم). '
            .'Use the returned ids with update_user / delete_user and the role names with create_user / update_user. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $users = User::query()
            ->with('roles')
            ->orderBy('id')
            ->get();

        $roleOptions = Role::query()->orderBy('name')->pluck('name');

        if ($users->isEmpty()) {
            return ActionResult::text('لا يوجد مستخدمون بعد.');
        }

        $lines = $users->map(fn (User $panelUser): string => sprintf(
            '- [%d] %s | %s | الأدوار: %s | المراجعة: %s | التوثيق: %s',
            $panelUser->id,
            $panelUser->name,
            $panelUser->email,
            $panelUser->getRoleNames()->isEmpty() ? '—' : $panelUser->getRoleNames()->implode('، '),
            $panelUser->requires_review ? 'مطلوبة' : 'غير مطلوبة',
            $panelUser->email_verified_at !== null ? 'موثّق' : 'غير موثّق',
        ))->all();

        return ActionResult::text(
            "مستخدمو اللوحة (id | الاسم | البريد | الأدوار | المراجعة | التوثيق):\n"
            .implode("\n", $lines)
            ."\n\nالأدوار المتاحة للإسناد: ".($roleOptions->isEmpty() ? '—' : $roleOptions->implode('، ')),
        );
    }
}
