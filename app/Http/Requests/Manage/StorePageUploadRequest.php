<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePageUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Pages are editable by every panel user (parity with the Filament
     * panel, where page CRUD is gated on panel access only).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `editor` uploads are the rich-editor image attachments (images only,
     * like Filament's RichEditor); `quick_response` uploads are the Telegram
     * quick-response attachments (images and documents).
     *
     * @return array<string, array<int, \Illuminate\Validation\Rules\In|string>>
     */
    public function rules(): array
    {
        $mimes = $this->input('type') === 'quick_response'
            ? 'mimes:jpg,jpeg,png,gif,webp,avif,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,mp3,mp4,ogg,webm'
            : 'mimes:jpg,jpeg,png,gif,webp,avif';

        return [
            'type' => ['required', 'string', Rule::in(['editor', 'quick_response'])],
            'file' => ['required', 'file', $mimes, 'max:12288'],
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
            'type.required' => 'نوع الرفع مطلوب.',
            'type.in' => 'نوع الرفع غير صالح.',
            'file.required' => 'الملف مطلوب.',
            'file.file' => 'الملف المرفوع غير صالح.',
            'file.mimes' => 'نوع الملف غير مدعوم.',
            'file.max' => 'يجب ألا يتجاوز حجم الملف ١٢ ميجابايت.',
        ];
    }
}
