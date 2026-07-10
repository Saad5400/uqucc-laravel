<?php

namespace App\Services\Calculators;

/**
 * Deprivation (حرمان) calculator — how many absence hours a student has left
 * before being barred from a course's final exam.
 *
 * PHP port of resources/js/lib/calculators/deprivation.ts — the math must
 * stay identical to the TypeScript implementation; the Pest suite mirrors
 * the Vitest cases to prove it. UQU rules: 15% unexcused-absence cap and
 * 25% overall-absence cap over a 17-week term.
 */
class DeprivationCalculator
{
    /**
     * عدد أسابيع المقرر
     */
    public const WEEKS = 17;

    /**
     * حد الغياب بدون عذر (%15)
     */
    public const MAX_UNEXCUSED_RATE = 0.15;

    /**
     * حد الغياب الكلي (%25)
     */
    public const MAX_ABSENCE_RATE = 0.25;

    /**
     * @param  int  $lecturesPerWeek  عدد الساعات في الأسبوع
     * @param  int  $unexcusedCount  ساعات الغياب الحالية بدون عذر
     * @param  int  $excusedCount  ساعات الغياب الحالية بعذر
     */
    public function calculate(int $lecturesPerWeek, int $unexcusedCount, int $excusedCount): DeprivationResult
    {
        $totalHours = self::WEEKS * $lecturesPerWeek;

        $lectureWeight = $totalHours > 0 ? round(100 / $totalHours, 2) : 0.0;

        $total = $unexcusedCount + $excusedCount;
        $maxUnexcusedHours = (int) floor($totalHours * self::MAX_UNEXCUSED_RATE);
        $maxAbsenceHours = (int) floor($totalHours * self::MAX_ABSENCE_RATE);

        $byUnexcusedRule = $maxUnexcusedHours - $unexcusedCount;
        $byTotalRule = $maxAbsenceHours - $total;

        $unexcusedLeft = min($byUnexcusedRule, $byTotalRule);

        $absenceLeft = $maxAbsenceHours - $total;

        $currentAbsenceRate = $totalHours > 0 ? round(($total / $totalHours) * 100, 2) : 0.0;

        return new DeprivationResult(
            lectureWeight: $lectureWeight,
            totalHours: $totalHours,
            unexcusedLeft: $unexcusedLeft,
            absenceLeft: $absenceLeft,
            currentAbsenceRate: $currentAbsenceRate,
            isDeprived: $unexcusedLeft < 0 || $absenceLeft < 0,
        );
    }
}
