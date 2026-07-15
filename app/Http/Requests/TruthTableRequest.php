<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TruthTableRequest extends FormRequest
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
            'formula' => ['required', 'string', 'max:200'],
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
            'formula.required' => 'اكتب صيغة منطقية أولاً.',
            'formula.string' => 'يجب أن تكون الصيغة نصاً.',
            'formula.max' => 'يجب ألا تتجاوز الصيغة ٢٠٠ حرف.',
        ];
    }
}
