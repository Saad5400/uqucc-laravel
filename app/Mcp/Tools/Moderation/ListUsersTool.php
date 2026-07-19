<?php

namespace App\Mcp\Tools\Moderation;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Spatie\Permission\Models\Role;

/**
 * The panel users with their roles and ids, plus the assignable role names —
 * the lookup a client needs before create_user / update_user / delete_user.
 */
#[IsReadOnly]
#[Description('List the panel users with their roles, review flag and ids, plus the assignable role names (قائمة مستخدمي اللوحة وأدوارهم). Use the ids with update_user / delete_user and the role names with create_user / update_user.')]
class ListUsersTool extends ModerationTool
{
    protected string $name = 'list_users';

    protected function requiredAbility(): string
    {
        return 'manage-users';
    }

    protected function perform(Request $request, User $user): Response
    {
        $users = User::query()
            ->with('roles')
            ->orderBy('id')
            ->get()
            ->map(fn (User $panelUser): array => [
                'id' => $panelUser->id,
                'name' => $panelUser->name,
                'email' => $panelUser->email,
                'roles' => $panelUser->getRoleNames()->values(),
                'requires_review' => $panelUser->requires_review,
                'verified' => $panelUser->email_verified_at !== null,
            ]);

        return Response::text((string) json_encode([
            'users' => $users,
            'role_options' => Role::query()->orderBy('name')->pluck('name'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
