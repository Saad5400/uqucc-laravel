<?php

namespace App\Ai\Admin\Actions\Users;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Http\Requests\Manage\StoreUserRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Saad\AiKit\Approvals\Classified\Field;
use Saad\AiKit\Approvals\Classified\FieldWidget;

/**
 * Create a panel user. Mirrors
 * {@see \App\Http\Controllers\Manage\UserController::store()} and the MCP
 * `create_user` tool: the email starts verified, and roles / requires_review
 * only apply when the acting user holds `assign-roles`. Reuses
 * {@see StoreUserRequest}'s rules and messages, dropping only its form-only
 * `confirmed` password rule.
 */
class CreateUserAction extends AdminAction
{
    public function name(): string
    {
        return 'create_user';
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
        return 'Create a new admin-panel user. '
            .'Provide name, email and password. Roles and the requires_review flag are applied only if you hold the '
            .'assign-roles permission. The email is marked verified on creation.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $rules = (new StoreUserRequest)->rules();
        $rules['password'] = array_values(array_filter(
            $rules['password'],
            fn (mixed $rule): bool => $rule !== 'confirmed',
        ));

        $validator = Validator::make($input, $rules, (new StoreUserRequest)->messages());

        if ($validator->fails()) {
            throw new AdminActionException($validator->errors()->first());
        }

        $data = $validator->validated();

        $canAssignRoles = $user->can('assign-roles');

        return [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'roles' => $canAssignRoles ? ($data['roles'] ?? []) : [],
            'requires_review' => $canAssignRoles ? (bool) ($data['requires_review'] ?? false) : false,
            'apply_roles' => $canAssignRoles,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        $summary = 'إنشاء مستخدم جديد «'.$normalized['name'].'» بالبريد '.$normalized['email'].'.';

        if ($normalized['apply_roles'] && $normalized['roles'] !== []) {
            $summary .= ' الأدوار: '.implode('، ', $normalized['roles']).'.';
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $panelUser = new User([
            'name' => $normalized['name'],
            'email' => $normalized['email'],
            'password' => $normalized['password'],
        ]);
        $panelUser->email_verified_at = now();

        if ($normalized['apply_roles']) {
            $panelUser->requires_review = (bool) $normalized['requires_review'];
        }

        $panelUser->save();

        if ($normalized['apply_roles']) {
            $panelUser->syncRoles($normalized['roles']);
        }

        return ActionResult::text('تم إنشاء المستخدم «'.$panelUser->name.'» (id: '.$panelUser->id.').');
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
                ->description('Role names to assign (from list_users). Applied only if you hold assign-roles.')
                ->items($schema->string()),
            'requires_review' => $schema->boolean()
                ->description('Whether this user\'s content edits must be reviewed before going live. Applied only if you hold assign-roles.'),
        ];
    }

    /**
     * Arabic labels for the approval card, with each field's widget restated
     * alongside its label. Declaring a spec REPLACES the kit's value-based
     * inference for that argument, so the widget an unlabelled field would
     * have been given has to be named here — an id declared without
     * `Field::readonly` would come back as an editable text box.
     *
     * @return array<string, mixed>
     */
    public function fieldWidgets(): array
    {
        return [
            'name' => Field::make('name', FieldWidget::Text, label: 'الاسم'),
            'email' => Field::make('email', FieldWidget::Text, label: 'البريد الإلكتروني'),
            'password' => Field::make('password', FieldWidget::Text, label: 'كلمة المرور'),
            'roles' => Field::readonly('roles', label: 'الأدوار'),
            'requires_review' => Field::make('requires_review', FieldWidget::Boolean, label: 'تخضع تعديلاته للمراجعة'),
        ];
    }
}
