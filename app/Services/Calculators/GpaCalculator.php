<?php

namespace App\Services\Calculators;

/**
 * UQU 4.0-scale GPA calculator.
 *
 * PHP port of resources/js/lib/calculators/gpa.ts — the math (grade table,
 * counting rules, rounding) must stay identical to the TypeScript
 * implementation; the Pest suite mirrors the Vitest cases to prove it.
 *
 * Only rows that have BOTH a credit value > 0 and a valid grade are counted:
 *  - gpa: exact average rounded to 5 decimals
 *  - approximateGpa: average rounded to 2 decimals
 *  - totalCredits / totalPoints: sums over counted rows
 */
class GpaCalculator
{
    /**
     * UQU letter grades on the 4.0 scale — keep identical to `gradeValues`
     * in resources/js/lib/calculators/gpa.ts.
     *
     * @var array<string, float>
     */
    public const GRADE_VALUES = [
        'A+' => 4.0,
        'A' => 3.75,
        'B+' => 3.5,
        'B' => 3.0,
        'C+' => 2.5,
        'C' => 2.0,
        'D+' => 1.5,
        'D' => 1.0,
        'F' => 0.0,
    ];

    /**
     * @param  list<array{credits: string, grade?: string|null}>  $courses
     */
    public function calculate(array $courses): GpaResult
    {
        $creditsSum = 0.0;
        $pointsSum = 0.0;

        foreach ($courses as $course) {
            $creditValue = ArabicNumberParser::parse($course['credits'] ?? '');
            $grade = $course['grade'] ?? null;

            if ($creditValue > 0 && $grade !== null && array_key_exists($grade, self::GRADE_VALUES)) {
                $creditsSum += $creditValue;
                $pointsSum += $creditValue * self::GRADE_VALUES[$grade];
            }
        }

        $average = $creditsSum > 0 ? $pointsSum / $creditsSum : 0.0;

        return new GpaResult(
            gpa: round($average, 5),
            approximateGpa: round($average, 2),
            totalCredits: $creditsSum,
            totalPoints: $pointsSum,
        );
    }
}
