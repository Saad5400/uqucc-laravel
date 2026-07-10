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
     * PDF, image, or plain-text/markdown file, 20 MB max — the same
     * constraints the upload form advertises. Mimetypes are validated
     * against the stored bytes (not the client-supplied type); markdown
     * files sniff as text/plain, but the markdown mimes are listed for
     * systems that detect them explicitly.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'file' => [
                'required',
                'file',
                'mimetypes:application/pdf,image/png,image/jpeg,image/webp,text/plain,text/markdown,text/x-markdown',
                'max:20480',
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
            'title.required' => 'حقل العنوان مطلوب.',
            'title.max' => 'العنوان طويل جداً.',
            'file.required' => 'حقل الملف مطلوب.',
            'file.file' => 'الملف غير صالح.',
            'file.mimetypes' => 'الملف يجب أن يكون PDF أو صورة (PNG / JPG / WebP) أو ملفاً نصياً (TXT / MD).',
            'file.max' => 'حجم الملف يتجاوز الحد الأقصى (20 ميجابايت).',
        ];
    }
}
