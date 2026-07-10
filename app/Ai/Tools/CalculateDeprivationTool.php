<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\GatedByAiSettings;
use App\Services\Calculators\DeprivationCalculator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Deprivation (حرمان) calculation — same math as the site's deprivation
 * calculator: 15% unexcused / 25% overall absence caps over a 17-week term.
 * Read-only, pure computation.
 */
class CalculateDeprivationTool implements Tool
{
    use GatedByAiSettings;

    public function __construct(private readonly DeprivationCalculator $calculator) {}

    public function description(): Stringable|string
    {
        return 'Calculate absence/deprivation (الحرمان) status for a UQU course: how many absence hours remain before the student '
            .'is barred from the final exam (حساب ساعات الغياب المتبقية قبل الحرمان). '
            .'UQU rules over a 17-week term: at most 15% unexcused absence and 25% total absence. '
            .'Pass the course\'s weekly contact hours and the absence hours so far.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->aiToolsAreDisabled()) {
            return $this->aiDisabledReply();
        }

        $lecturesPerWeek = $request->integer('lectures_per_week');
        $unexcused = $request->integer('unexcused_hours');
        $excused = $request->integer('excused_hours');

        if ($lecturesPerWeek < 1) {
            return 'يرجى إدخال عدد ساعات المقرر في الأسبوع (1 على الأقل). lectures_per_week must be at least 1.';
        }

        if ($unexcused < 0 || $excused < 0) {
            return 'ساعات الغياب لا يمكن أن تكون سالبة. Absence hours cannot be negative.';
        }

        $result = $this->calculator->calculate($lecturesPerWeek, $unexcused, $excused);

        $status = $result->isDeprived
            ? 'محروم — تم تجاوز حد الغياب (DEPRIVED: an absence cap is exceeded)'
            : 'غير محروم (not deprived)';

        return implode("\n", [
            "الحالة (status): {$status}",
            "إجمالي ساعات الفصل (total term hours): {$result->totalHours}",
            "وزن الساعة الواحدة (weight of one hour): {$result->lectureWeight}%",
            "نسبة الغياب الحالية (current absence rate): {$result->currentAbsenceRate}%",
            "الساعات المتبقية للغياب بدون عذر (unexcused hours left): {$result->unexcusedLeft}",
            "الساعات المتبقية للغياب الكلي (total absence hours left): {$result->absenceLeft}",
        ]);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'lectures_per_week' => $schema->integer()
                ->description('Weekly contact hours of the course (عدد ساعات المقرر في الأسبوع), at least 1.')
                ->required(),
            'unexcused_hours' => $schema->integer()
                ->description('Absence hours WITHOUT an accepted excuse so far (ساعات الغياب بدون عذر). Defaults to 0.'),
            'excused_hours' => $schema->integer()
                ->description('Absence hours WITH an accepted excuse so far (ساعات الغياب بعذر). Defaults to 0.'),
        ];
    }
}
