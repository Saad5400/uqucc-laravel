<?php

namespace App\Services\Calculators;

/**
 * Internal-transfer composite score ("مركبة التحويل") calculator.
 *
 * PHP port of resources/js/lib/calculators/transfer.ts — the math must stay
 * identical to the TypeScript implementation; the Pest suite mirrors the
 * Vitest cases to prove it.
 *
 * `weightedScore` is a percentage (out of 100); `cumulativeGpa` is out of 4.
 * Multipliers are derived from the configured percentage split:
 *   weightedMultiplier = weightedPercentage / 100
 *   gpaMultiplier      = gpaPercentage / 4
 *
 * Returns null when either main input is <= 0 (mirrors the UI which hides
 * the result card in that case).
 */
class TransferCalculator
{
    public function calculate(
        string $weightedScore,
        string $cumulativeGpa,
        string $weightedPercentage = '50',
        string $gpaPercentage = '50',
    ): ?float {
        $weighted = ArabicNumberParser::parse($weightedScore);
        $gpa = ArabicNumberParser::parse($cumulativeGpa);

        if ($weighted <= 0 || $gpa <= 0) {
            return null;
        }

        $weightedMultiplier = ArabicNumberParser::parse($weightedPercentage) / 100;
        $gpaMultiplier = ArabicNumberParser::parse($gpaPercentage) / 4;

        return $weighted * $weightedMultiplier + $gpa * $gpaMultiplier;
    }
}
