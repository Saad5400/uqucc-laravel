<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The resume payload for a paused assistant turn: one decision per pending
 * tool-call id. Shape validation stops at "a non-empty map" — the semantic
 * mapping (approve / reject / edit) is owned by ai-kit's
 * ResumeDecisions::fromClient(), which throws on anything malformed.
 */
class AdminAssistantDecisionRequest extends FormRequest
{
    /**
     * The route group already requires panel access; ownership of the
     * conversation is enforced in the controller.
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
            'decisions' => ['required', 'array', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decisions.required' => 'لا توجد قرارات لإرسالها.',
            'decisions.array' => 'صيغة القرارات غير صالحة.',
            'decisions.min' => 'لا توجد قرارات لإرسالها.',
        ];
    }
}
