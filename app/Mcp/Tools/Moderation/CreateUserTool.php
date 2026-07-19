<?php

namespace App\Mcp\Tools\Moderation;

use App\Http\Requests\Manage\StoreUserRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * Create a panel user. Mirrors
 * {@see \App\Http\Controllers\Manage\UserController::store()}: the email
 * starts verified, and roles / requires_review only apply when the acting
 * moderator holds `assign-roles`. Reuses {@see StoreUserRequest}'s rules and
 * messages, dropping only its form-only `confirmed` password rule.
 */
#[Description('Create a new panel user (إضافة مستخدم جديد للوحة). Provide name, email and password. Roles and the requires_review flag are applied only if you hold the assign-roles permission. The email is marked verified on creation.')]
class CreateUserTool extends ModerationTool
{
    protected string $name = 'create_user';

    protected function requiredAbility(): string
    {
        return 'manage-users';
    }

    protected function perform(Request $request, User $user): Response
    {
        $rules = (new StoreUserRequest)->rules();
        $rules['password'] = array_values(array_filter(
            $rules['password'],
            fn (mixed $rule): bool => $rule !== 'confirmed',
        ));

        $data = $this->validateInput($request, $rules, (new StoreUserRequest)->messages());

        if ($data instanceof Response) {
            return $data;
        }

        $panelUser = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $panelUser->email_verified_at = now();

        if ($user->can('assign-roles')) {
            $panelUser->requires_review = (bool) ($data['requires_review'] ?? false);
        }

        $panelUser->save();

        if ($user->can('assign-roles')) {
            $panelUser->syncRoles($data['roles'] ?? []);
        }

        return Response::text('تم إنشاء المستخدم «'.$panelUser->name.'» (id: '.$panelUser->id.'). User created.');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('The user\'s display name.')
                ->required(),
            'email' => $schema->string()
                ->description('The user\'s email address. Must be unique.')
                ->required(),
            'password' => $schema->string()
                ->description('The user\'s password (at least 8 characters).')
                ->required(),
            'roles' => $schema->array()
                ->description('Role names to assign (from list_users role_options). Applied only if you hold assign-roles.')
                ->items($schema->string()),
            'requires_review' => $schema->boolean()
                ->description('Whether this user\'s content edits must be reviewed before going live. Applied only if you hold assign-roles.'),
        ];
    }
}
