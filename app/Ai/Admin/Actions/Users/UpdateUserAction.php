<?php

namespace App\Ai\Admin\Actions\Users;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Http\Requests\Manage\UpdateUserRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Update a panel user. Mirrors
 * {@see \App\Http\Controllers\Manage\UserController::update()} and the MCP
 * `update_user` tool: the password only changes when provided, verification /
 * roles / requires_review follow the same rules, and — like
 * {@see UpdateUserRequest}'s `after()` hook — a user holding `assign-roles`
 * cannot strip the admin role from their own account. Only fields you send are
 * changed.
 *
 * Reuses {@see UpdateUserRequest}'s rules and messages, but overrides the
 * unique email / username rules to ignore the target's own id (the request
 * derives that id from the route model, which is absent on these surfaces) and
 * drops the form-only `confirmed` password rule.
 */
class UpdateUserAction extends AdminAction
{
    public function name(): string
    {
        return 'update_user';
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
        return 'Update an existing panel user (تعديل بيانات مستخدم). '
            .'Requires user_id (from list_users). Only the fields you send change. Password changes only when provided; '
            .'roles / requires_review apply only if you hold assign-roles.';
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

        $rules = (new UpdateUserRequest)->rules();
        $rules['email'] = ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)];
        $rules['username'] = ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($target->id)];
        $rules['password'] = array_values(array_filter(
            $rules['password'],
            fn (mixed $rule): bool => $rule !== 'confirmed',
        ));

        $validator = Validator::make($input, $rules, (new UpdateUserRequest)->messages());

        $validator->after(function (ValidatorInstance $validator) use ($input, $user, $target): void {
            $roles = $input['roles'] ?? null;

            if (! $user->is($target) || ! is_array($roles) || ! $user->can('assign-roles')) {
                return;
            }

            if ($target->hasRole('admin') && ! in_array('admin', $roles, true)) {
                $validator->errors()->add('roles', 'لا يمكنك إزالة دور المدير من حسابك.');
            }
        });

        if ($validator->fails()) {
            throw new AdminActionException($validator->errors()->first());
        }

        $data = $validator->validated();

        $attributes = [
            'name' => $data['name'],
            'email' => $data['email'],
        ];

        foreach (['username', 'url', 'avatar'] as $optional) {
            if (array_key_exists($optional, $input)) {
                $attributes[$optional] = $data[$optional] ?? null;
            }
        }

        $normalized = [
            'user_id' => $target->id,
            'user_name' => $target->name,
            'attributes' => $attributes,
        ];

        if (array_key_exists('password', $input) && ($data['password'] ?? null) !== null && $data['password'] !== '') {
            $normalized['password'] = $data['password'];
        }

        if (array_key_exists('verified', $input)) {
            $normalized['verified'] = filter_var($input['verified'], FILTER_VALIDATE_BOOL);
        }

        if (array_key_exists('requires_review', $input) && $user->can('assign-roles')) {
            $normalized['requires_review'] = filter_var($input['requires_review'], FILTER_VALIDATE_BOOL);
        }

        if (array_key_exists('roles', $input) && $user->can('assign-roles')) {
            $normalized['roles'] = $data['roles'] ?? [];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        $labels = [];

        foreach (array_keys($normalized['attributes']) as $key) {
            $labels[] = self::fieldLabel($key);
        }

        if (array_key_exists('password', $normalized)) {
            $labels[] = 'كلمة المرور';
        }

        if (array_key_exists('verified', $normalized)) {
            $labels[] = 'التوثيق';
        }

        if (array_key_exists('requires_review', $normalized)) {
            $labels[] = 'إلزام المراجعة';
        }

        if (array_key_exists('roles', $normalized)) {
            $labels[] = 'الأدوار';
        }

        return 'تعديل بيانات المستخدم «'.$normalized['user_name'].'» ('.implode('، ', $labels).').';
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

        $target->fill($normalized['attributes']);

        if (array_key_exists('password', $normalized)) {
            $target->password = $normalized['password'];
        }

        if (array_key_exists('verified', $normalized)) {
            $target->email_verified_at = $normalized['verified'] ? ($target->email_verified_at ?? now()) : null;
        }

        if (array_key_exists('requires_review', $normalized)) {
            $target->requires_review = (bool) $normalized['requires_review'];
        }

        $target->save();

        if (array_key_exists('roles', $normalized)) {
            $target->syncRoles($normalized['roles']);
        }

        return ActionResult::text('تم تحديث بيانات المستخدم «'.$target->name.'».');
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

    private static function fieldLabel(string $key): string
    {
        return match ($key) {
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'username' => 'اسم المستخدم',
            'url' => 'الرابط',
            'avatar' => 'الصورة',
            default => $key,
        };
    }
}
