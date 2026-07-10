<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class StoreCorpusTextRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Any panel user may manage the AI corpus documents (parity with
     * {@see StoreCorpusDocumentRequest}).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The pasted text must be substantial enough to be worth retrieving
     * (50+ characters) and small enough to chunk sanely (500K characters,
     * roughly 500 KB of Latin text) — the same bounds the paste dialog
     * advertises.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'min:50', 'max:500000'],
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
            'content.required' => 'حقل النص مطلوب.',
            'content.min' => 'النص قصير جداً — أدخل ٥٠ حرفاً على الأقل.',
            'content.max' => 'النص يتجاوز الحد الأقصى (٥٠٠ ألف حرف).',
        ];
    }
}
