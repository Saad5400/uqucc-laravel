<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class ChatAttachmentRequest extends FormRequest
{
    /**
     * The endpoint is public (anonymous, session-identified); feature and
     * budget gating happen in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
                'max:10240',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'اختر ملفاً أولاً.',
            'file.file' => 'الملف المرفوع غير صالح.',
            'file.mimetypes' => 'نوع الملف غير مدعوم — يُقبل PDF أو صورة (JPEG، PNG، WebP).',
            'file.max' => 'يجب ألا يتجاوز حجم الملف ١٠ ميجابايت.',
        ];
    }
}
