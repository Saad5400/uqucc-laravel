<?php

namespace App\Http\Requests;

use App\Services\Numbers\BaseConverter;
use Illuminate\Foundation\Http\FormRequest;

class BaseConversionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The endpoint is public, read-only, pure computation.
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
            'number' => ['required', 'string', 'max:80'],
            'from_base' => ['required', 'integer', 'between:'.BaseConverter::MIN_BASE.','.BaseConverter::MAX_BASE],
            'to_base' => ['required', 'integer', 'between:'.BaseConverter::MIN_BASE.','.BaseConverter::MAX_BASE],
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
            'number.required' => 'اكتب العدد المراد تحويله أولاً.',
            'number.string' => 'يجب أن يكون العدد نصاً.',
            'number.max' => 'يجب ألا يتجاوز العدد ٨٠ حرفاً.',
            'from_base.required' => 'اختر الأساس المصدر.',
            'to_base.required' => 'اختر الأساس الهدف.',
            'from_base.integer' => 'يجب أن يكون الأساس المصدر رقماً صحيحاً.',
            'to_base.integer' => 'يجب أن يكون الأساس الهدف رقماً صحيحاً.',
            'from_base.between' => 'يجب أن يكون الأساس المصدر بين ٢ و ٣٦.',
            'to_base.between' => 'يجب أن يكون الأساس الهدف بين ٢ و ٣٦.',
        ];
    }
}
