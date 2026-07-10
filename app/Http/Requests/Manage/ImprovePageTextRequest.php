<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class ImprovePageTextRequest extends FormRequest
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
     * an array — or a legacy HTML string; the copilot improves what the
     * admin sees, not what was last saved.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'content' => ['nullable'],
            'instruction' => ['nullable', 'string', 'max:1000'],
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
            'instruction.string' => 'التعليمات الإضافية غير صالحة.',
            'instruction.max' => 'التعليمات الإضافية طويلة جداً.',
        ];
    }
}
