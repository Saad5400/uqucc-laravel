<?php

namespace App\Support;

use DateTimeInterface;
use IntlDateFormatter;

/**
 * Umm al-Qura (أم القرى) Hijri dates — the calendar Saudi Arabia actually runs on.
 *
 * This is the single source of Hijri conversion in the app. It uses ICU's
 * `islamic-umalqura` calendar, which follows the official Umm al-Qura tables
 * published by Makkah. The tabular/arithmetic calendars (`islamic`,
 * `islamic-civil`, and ar-php's Hijri mode) are *not* interchangeable with it —
 * they drift up to two days away from the Saudi date.
 */
class HijriDate
{
    private const CALENDAR = 'islamic-umalqura';

    /**
     * Numeric Umm al-Qura date with Latin digits, e.g. `1448-04-16`.
     */
    public static function numeric(DateTimeInterface $moment, ?string $timezone = null): string
    {
        return self::format($moment, 'en@calendar='.self::CALENDAR, 'yyyy-MM-dd', $timezone);
    }

    /**
     * Long Arabic Umm al-Qura date, e.g. `١٦ ربيع الآخر ١٤٤٨ هـ`.
     */
    public static function longArabic(DateTimeInterface $moment, ?string $timezone = null): string
    {
        return self::format($moment, 'ar_SA@calendar='.self::CALENDAR, null, $timezone);
    }

    private static function format(DateTimeInterface $moment, string $locale, ?string $pattern, ?string $timezone): string
    {
        $formatter = new IntlDateFormatter(
            $locale,
            $pattern === null ? IntlDateFormatter::LONG : IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $timezone ?? $moment->getTimezone()->getName(),
            IntlDateFormatter::TRADITIONAL,
            $pattern,
        );

        return (string) $formatter->format($moment);
    }
}
