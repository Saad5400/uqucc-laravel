<?php

namespace App\Helpers;

/**
 * Arabic count phrases with correct number agreement — "10 نقطة" is broken
 * Arabic; the counted noun changes with the number:
 *
 *   1      → نقطة واحدة   (singular + واحدة, no numeral)
 *   2      → نقطتان       (dual, no numeral)
 *   3–10   → 5 نقاط       (numeral + plural)
 *   0, 11+ → 15 نقطة      (numeral + singular — the تمييز rule)
 */
class ArabicPlural
{
    public static function of(int $count, string $singular, string $dual, string $plural, string $feminineOne = 'واحدة'): string
    {
        return match (true) {
            $count === 1 => $singular.' '.$feminineOne,
            $count === 2 => $dual,
            $count >= 3 && $count <= 10 => $count.' '.$plural,
            default => $count.' '.$singular,
        };
    }

    public static function points(int $count): string
    {
        return self::of($count, 'نقطة', 'نقطتان', 'نقاط');
    }

    public static function days(int $count): string
    {
        return self::of($count, 'يوم', 'يومان', 'أيام', 'واحد');
    }

    public static function people(int $count): string
    {
        return self::of($count, 'مشارك', 'مشاركان', 'مشاركين', 'واحد');
    }

    public static function answers(int $count): string
    {
        return self::of($count, 'إجابة', 'إجابتان', 'إجابات');
    }
}
