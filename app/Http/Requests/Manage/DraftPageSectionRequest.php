<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class DraftPageSectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Any panel user may use the copilot on pages (pages carry no extra
     * permission beyond panel access); the feature flag is enforced by
     * {@see \App\Ai\Copilot\PageCopilot} itself.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `content` is the editor's CURRENT (possibly unsaved) TipTap document —
     * an array — or a legacy HTML string; the drafted section is appended
     * after it.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'content' => ['nullable'],
            'topic' => ['required', 'string', 'max:200'],
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
            'topic.required' => 'حقل موضوع القسم مطلوب.',
            'topic.string' => 'موضوع القسم غير صالح.',
            'topic.max' => 'موضوع القسم طويل جداً.',
        ];
    }
}
