<?php

namespace App\Http\Requests\Manage;

use App\Ai\OpinionPoll\OpinionPollTheme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateOpinionPollRequest extends FormRequest
{
    /**
     * Any panel user may trigger generation (parity with the poll settings).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `theme` is optional: omit it to let the rotation pick the angle that has
     * waited longest, or name one to force it. `date` is the day to generate
     * for — omit it for today. Past days are refused: only today's poll is
     * ever posted, so a back-dated generation would spend budget on a poll
     * nobody will see (`poll:generate --date` stays unrestricted as a backfill
     * escape hatch).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date', 'after_or_equal:today'],
            'theme' => ['nullable', 'string', Rule::enum(OpinionPollTheme::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date' => 'تاريخ التوليد غير صالح.',
            'date.after_or_equal' => 'لا يمكن توليد استطلاع ليوم مضى — اختر اليوم أو يوماً قادماً.',
            'theme.enum' => 'الزاوية المختارة غير معروفة.',
        ];
    }
}
