<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The endpoint is public; feature gating happens in the controller
     * against AiSettings so a disabled toggle yields a consistent JSON shape.
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
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
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
            'q.required' => 'حقل البحث مطلوب.',
            'q.string' => 'يجب أن يكون نص البحث نصاً.',
            'q.min' => 'يجب أن يتكون نص البحث من حرفين على الأقل.',
            'q.max' => 'يجب ألا يتجاوز نص البحث ١٠٠ حرف.',
            'limit.integer' => 'يجب أن يكون حد النتائج رقماً صحيحاً.',
            'limit.min' => 'يجب أن يكون حد النتائج ١ على الأقل.',
            'limit.max' => 'يجب ألا يتجاوز حد النتائج ٢٠.',
        ];
    }
}
