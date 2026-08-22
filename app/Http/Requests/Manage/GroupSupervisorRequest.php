<?php

namespace App\Http\Requests\Manage;

use App\Models\StudentGroup\GroupSupervisor;
use App\Models\StudentGroup\SupervisorSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation for creating and editing a {@see GroupSupervisor}: the two
 * differ only in which group they resolve against and whether an existing row
 * is exempt from the duplicate-contact check.
 */
abstract class GroupSupervisorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `can:manage-student-groups` route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** The group the supervisor belongs to, for the per-group duplicate check. */
    abstract protected function groupId(): ?int;

    /** The row exempt from the duplicate check (the one being edited). */
    abstract protected function ignoreId(): ?int;

    /**
     * How each field must be present. Creating needs them; editing accepts
     * partial payloads, so the availability switch in the supervisor list can
     * send `is_available` on its own without restating the row.
     *
     * @return array<int, string>
     */
    abstract protected function presence(): array;

    /**
     * Normalize both contacts before the rules see them, so a pasted profile
     * URL or a spaced-out phone number is validated — and reported on — as the
     * value that will actually be stored.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('telegram_username'))) {
            $username = GroupSupervisor::normalizeUsername($this->input('telegram_username'));
            $normalized['telegram_username'] = $username === '' ? null : $username;
        }

        if (is_string($this->input('whatsapp_number'))) {
            $number = GroupSupervisor::normalizeWhatsapp($this->input('whatsapp_number'));
            $normalized['whatsapp_number'] = $number === '' ? null : $number;
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $presence = $this->presence();

        return [
            'name' => [...$presence, 'required', 'string', 'max:255'],
            'telegram_username' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^[A-Za-z][A-Za-z0-9_]{3,31}$/',
                Rule::unique('group_supervisors', 'telegram_username')
                    ->where('student_group_id', $this->groupId())
                    ->ignore($this->ignoreId()),
            ],
            'whatsapp_number' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^\d{8,15}$/',
                Rule::unique('group_supervisors', 'whatsapp_number')
                    ->where('student_group_id', $this->groupId())
                    ->ignore($this->ignoreId()),
            ],
            'section' => [...$presence, 'required', 'string', Rule::in(SupervisorSection::values())],
            'is_available' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A supervisor nobody can reach is not a supervisor — the whole row exists
     * to give a newcomer somewhere to send their message.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->touchesContacts()) {
                    return;
                }

                if ($this->resolvedContact('telegram_username') === null && $this->resolvedContact('whatsapp_number') === null) {
                    $validator->errors()->add('telegram_username', 'أضف معرّف تيليجرام أو رقم واتساب على الأقل.');
                }
            },
        ];
    }

    /** Whether this payload could leave the supervisor with no contact at all. */
    private function touchesContacts(): bool
    {
        return $this->has('telegram_username') || $this->has('whatsapp_number');
    }

    /** The value a partial payload leaves in place for a contact column. */
    private function resolvedContact(string $field): ?string
    {
        if ($this->has($field)) {
            return $this->input($field);
        }

        $supervisor = $this->route('supervisor');

        return $supervisor instanceof GroupSupervisor ? $supervisor->{$field} : null;
    }

    /**
     * Get the custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'حقل اسم المشرف مطلوب.',
            'name.string' => 'يجب أن يكون اسم المشرف نصاً.',
            'name.max' => 'يجب ألا يتجاوز اسم المشرف ٢٥٥ حرفاً.',
            'telegram_username.string' => 'يجب أن يكون معرّف تيليجرام نصاً.',
            'telegram_username.regex' => 'معرّف تيليجرام غير صالح. يبدأ بحرف إنجليزي ويتكوّن من حروف وأرقام وشرطة سفلية فقط.',
            'telegram_username.unique' => 'هذا المعرّف مضاف مسبقاً في هذا القروب.',
            'whatsapp_number.string' => 'يجب أن يكون رقم الواتساب نصاً.',
            'whatsapp_number.regex' => 'رقم الواتساب غير صالح. اكتبه بصيغة ٠٥XXXXXXXX أو بصيغة دولية.',
            'whatsapp_number.unique' => 'هذا الرقم مضاف مسبقاً في هذا القروب.',
            'section.required' => 'حقل الشطر مطلوب.',
            'section.in' => 'الشطر المحدد غير صالح.',
            'is_available.boolean' => 'حالة التوفر غير صالحة.',
        ];
    }
}
