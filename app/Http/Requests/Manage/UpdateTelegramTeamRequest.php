<?php

namespace App\Http\Requests\Manage;

use App\Models\TelegramTeam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTelegramTeamRequest extends FormRequest
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
        /** @var TelegramTeam $team */
        $team = $this->route('team');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:32',
                Rule::unique('telegram_teams', 'name')->where('chat_id', $team->chat_id)->ignore($team->id),
            ],
            'category_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('telegram_team_categories', 'id')->where('chat_id', $team->chat_id),
            ],
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
            'name.required' => 'اسم الفريق مطلوب.',
            'name.max' => 'اسم الفريق طويل — الحد الأقصى 32 حرفًا.',
            'name.unique' => 'يوجد فريق بهذا الاسم في هذه المجموعة.',
            'category_id.exists' => 'التصنيف غير موجود في هذه المجموعة.',
        ];
    }
}
