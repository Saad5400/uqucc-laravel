<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

class StoreCorpusDocumentRequest extends FormRequest
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
     * PDF or image, 20 MB max — the same constraints the upload form
     * advertises. Text is extracted from the stored bytes after upload.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimetypes:application/pdf,image/png,image/jpeg,image/webp', 'max:20480'],
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
            'file.required' => 'حقل الملف مطلوب.',
            'file.file' => 'الملف غير صالح.',
            'file.mimetypes' => 'الملف يجب أن يكون PDF أو صورة (PNG / JPG / WebP).',
            'file.max' => 'حجم الملف يتجاوز الحد الأقصى (20 ميجابايت).',
        ];
    }
}
