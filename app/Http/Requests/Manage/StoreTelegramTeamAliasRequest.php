<?php

namespace App\Http\Requests\Manage;

use App\Models\TelegramTeam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTelegramTeamAliasRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Any panel user may manage the bot's group teams (parity with the
     * Telegram chats resource).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * Team names and aliases share one namespace per chat, and matching folds
     * spelling variants — so uniqueness is checked on the normalized value
     * rather than with a plain unique rule.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var TelegramTeam $team */
                $team = $this->route('team');
                $name = (string) $this->string('name')->trim();

                if ($name !== '' && TelegramTeam::nameIsTaken($team->chat_id, $name, $team->id)) {
                    $validator->errors()->add('name', 'هذا الاسم مستخدم بالفعل كفريق أو اختصار في هذه المجموعة.');
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
            'name.required' => 'الاختصار مطلوب.',
            'name.max' => 'الاختصار طويل — الحد الأقصى 32 حرفًا.',
        ];
    }
}
