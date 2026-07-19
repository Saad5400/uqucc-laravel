<?php

namespace App\Mcp\Tools\Moderation;

use App\Http\Requests\Manage\UpdateUserRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator as ValidatorInstance;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * Update a panel user. Mirrors
 * {@see \App\Http\Controllers\Manage\UserController::update()}: the password
 * only changes when provided, verification/roles/requires_review follow the
 * same rules, and — like {@see UpdateUserRequest}'s `after()` hook — a
 * moderator holding `assign-roles` cannot strip the admin role from their own
 * account. Only fields you send are changed.
 */
#[Description('Update an existing panel user (تعديل بيانات مستخدم). Requires user_id (from list_users). Only the fields you send change. Password changes only when provided; roles / requires_review apply only if you hold assign-roles.')]
class UpdateUserTool extends ModerationTool
{
    protected string $name = 'update_user';

    protected function requiredAbility(): string
    {
        return 'manage-users';
    }

    protected function perform(Request $request, User $user): Response
    {
        $arguments = $request->all();

        $target = User::query()->find((int) ($arguments['user_id'] ?? 0));

        if ($target === null) {
            return Response::error('لم يُعثر على المستخدم المطلوب. No user found for that id.');
        }

        $validator = Validator::make(
            $arguments,
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target)],
                'password' => ['nullable', 'string', Password::defaults()],
                'verified' => ['sometimes', 'boolean'],
                'username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($target)],
                'url' => ['nullable', 'string', 'url', 'max:255'],
                'avatar' => ['nullable', 'string', 'url', 'max:255'],
                'roles' => ['sometimes', 'array'],
                'roles.*' => ['string', 'exists:roles,name'],
                'requires_review' => ['sometimes', 'boolean'],
            ],
            (new UpdateUserRequest)->messages(),
        );

        $validator->after(function (ValidatorInstance $validator) use ($arguments, $user, $target): void {
            $roles = $arguments['roles'] ?? null;

            if (! $user->is($target) || ! is_array($roles) || ! $user->can('assign-roles')) {
                return;
            }

            if ($target->hasRole('admin') && ! in_array('admin', $roles, true)) {
                $validator->errors()->add('roles', 'لا يمكنك إزالة دور المدير من حسابك.');
            }
        });

        if ($validator->fails()) {
            return Response::error('تعذّر تنفيذ الإجراء: '.$validator->errors()->first());
        }

        $data = $validator->validated();

        $attributes = [
            'name' => $data['name'],
            'email' => $data['email'],
        ];

        foreach (['username', 'url', 'avatar'] as $optional) {
            if (array_key_exists($optional, $arguments)) {
                $attributes[$optional] = $data[$optional] ?? null;
            }
        }

        $target->fill($attributes);

        if (array_key_exists('password', $arguments) && ($data['password'] ?? null) !== null && $data['password'] !== '') {
            $target->password = $data['password'];
        }

        if (array_key_exists('verified', $arguments)) {
            $verified = filter_var($arguments['verified'], FILTER_VALIDATE_BOOL);
            $target->email_verified_at = $verified ? ($target->email_verified_at ?? now()) : null;
        }

        if (array_key_exists('requires_review', $arguments) && $user->can('assign-roles')) {
            $target->requires_review = filter_var($arguments['requires_review'], FILTER_VALIDATE_BOOL);
        }

        $target->save();

        if (array_key_exists('roles', $arguments) && $user->can('assign-roles')) {
            $target->syncRoles($data['roles'] ?? []);
        }

        return Response::text('تم تحديث بيانات المستخدم «'.$target->name.'». User updated.');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()
                ->description('The id of the user to update, from list_users.')
                ->required(),
            'name' => $schema->string()
                ->description('The user\'s display name.')
                ->required(),
            'email' => $schema->string()
                ->description('The user\'s email address. Must be unique.')
                ->required(),
            'password' => $schema->string()
                ->description('A new password (at least 8 characters). Omit to keep the current one.'),
            'verified' => $schema->boolean()
                ->description('Whether the email is verified.'),
            'username' => $schema->string()
                ->description('Optional unique username.'),
            'url' => $schema->string()
                ->description('Optional profile URL.'),
            'avatar' => $schema->string()
                ->description('Optional avatar image URL.'),
            'roles' => $schema->array()
                ->description('Role names to assign (replaces current roles). Applied only if you hold assign-roles.')
                ->items($schema->string()),
            'requires_review' => $schema->boolean()
                ->description('Whether this user\'s content edits must be reviewed. Applied only if you hold assign-roles.'),
        ];
    }
}
