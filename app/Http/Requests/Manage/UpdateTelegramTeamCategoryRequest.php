<?php

namespace App\Http\Requests\Manage;

use App\Models\TelegramTeamCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTelegramTeamCategoryRequest extends FormRequest
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
        /** @var TelegramTeamCategory $category */
        $category = $this->route('category');

        return [
            'name' => [
                'required',
                'string',
                'max:32',
                Rule::unique('telegram_team_categories', 'name')->where('chat_id', $category->chat_id)->ignore($category->id),
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
            'name.required' => 'اسم التصنيف مطلوب.',
            'name.max' => 'اسم التصنيف طويل — الحد الأقصى 32 حرفًا.',
            'name.unique' => 'يوجد تصنيف بهذا الاسم في هذه المجموعة.',
        ];
    }
}
