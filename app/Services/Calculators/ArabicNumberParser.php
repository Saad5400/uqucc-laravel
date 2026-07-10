<?php

namespace App\Services\Calculators;

/**
 * Converts Arabic-Indic digits and separators to a float.
 *
 * PHP port of resources/js/lib/calculators/parseArabicNumber.ts — the two
 * implementations must stay behaviorally identical (the Pest and Vitest
 * suites mirror each other's cases). Like JavaScript's `parseFloat(x) || 0`,
 * a leading numeric prefix parses and anything non-numeric collapses to 0.
 */
class ArabicNumberParser
{
    private const ARABIC_INDIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const LATIN_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    private const DECIMAL_SEPARATORS = ['٫', '،', ','];

    public static function parse(string $text = ''): float
    {
        $normalized = str_replace(
            self::DECIMAL_SEPARATORS,
            '.',
            str_replace(self::ARABIC_INDIC_DIGITS, self::LATIN_DIGITS, trim($text)),
        );

        return (float) $normalized ?: 0.0;
    }
}
