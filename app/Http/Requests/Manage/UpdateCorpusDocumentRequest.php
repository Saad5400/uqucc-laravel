<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCorpusDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Any panel user may manage the AI corpus documents (parity with the
     * previous admin resource).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'extracted_markdown' => ['nullable', 'string'],
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
            'title.required' => 'حقل العنوان مطلوب.',
            'title.max' => 'العنوان طويل جداً.',
            'extracted_markdown.string' => 'النص المستخرج غير صالح.',
        ];
    }
}
