<?php

namespace App\Http\Requests\Manage;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `can:manage-users` route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, \Illuminate\Validation\Rules\Password|\Illuminate\Validation\Rules\Unique|string>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
            'verified' => ['sometimes', 'boolean'],
            'username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user)],
            'url' => ['nullable', 'string', 'url', 'max:255'],
            'avatar' => ['nullable', 'string', 'url', 'max:255'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }

    /**
     * A user holding `assign-roles` must never strip the admin role from
     * their own account — that would lock them (and possibly everyone)
     * out of user management.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var User $user */
                $user = $this->route('user');
                $roles = $this->input('roles');

                if (! $this->user()->is($user) || ! is_array($roles) || ! $this->user()->can('assign-roles')) {
                    return;
                }

                if ($user->hasRole('admin') && ! in_array('admin', $roles, true)) {
                    $validator->errors()->add('roles', 'لا يمكنك إزالة دور المدير من حسابك.');
                }
            },
        ];
    }

    /**
     * Get the custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'حقل الاسم مطلوب.',
            'name.string' => 'يجب أن يكون الاسم نصاً.',
            'name.max' => 'يجب ألا يتجاوز الاسم ٢٥٥ حرفاً.',
            'email.required' => 'حقل البريد الإلكتروني مطلوب.',
            'email.string' => 'يجب أن يكون البريد الإلكتروني نصاً.',
            'email.email' => 'يجب إدخال بريد إلكتروني صالح.',
            'email.max' => 'يجب ألا يتجاوز البريد الإلكتروني ٢٥٥ حرفاً.',
            'email.unique' => 'هذا البريد الإلكتروني مستخدم بالفعل.',
            'password.string' => 'يجب أن تكون كلمة المرور نصاً.',
            'password.confirmed' => 'تأكيد كلمة المرور غير متطابق.',
            'password.min' => 'يجب أن تكون كلمة المرور ٨ أحرف على الأقل.',
            'verified.boolean' => 'قيمة توثيق البريد غير صالحة.',
            'username.string' => 'يجب أن يكون اسم المستخدم نصاً.',
            'username.max' => 'يجب ألا يتجاوز اسم المستخدم ٢٥٥ حرفاً.',
            'username.unique' => 'اسم المستخدم هذا مستخدم بالفعل.',
            'url.string' => 'يجب أن يكون الرابط نصاً.',
            'url.url' => 'يجب إدخال رابط صالح يبدأ بـ https:// أو http://.',
            'url.max' => 'يجب ألا يتجاوز الرابط ٢٥٥ حرفاً.',
            'avatar.string' => 'يجب أن يكون رابط الصورة نصاً.',
            'avatar.url' => 'يجب إدخال رابط صورة صالح يبدأ بـ https:// أو http://.',
            'avatar.max' => 'يجب ألا يتجاوز رابط الصورة ٢٥٥ حرفاً.',
            'roles.array' => 'قائمة الأدوار غير صالحة.',
            'roles.*.string' => 'الدور غير صالح.',
            'roles.*.exists' => 'أحد الأدوار المحددة غير موجود.',
        ];
    }
}
