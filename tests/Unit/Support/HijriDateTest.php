<?php

use App\Support\HijriDate;
use Carbon\CarbonImmutable;

function riyadhNoon(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date.' 12:00', 'Asia/Riyadh');
}

it('converts Gregorian dates to the Umm al-Qura calendar Saudi Arabia uses', function (string $gregorian, string $hijri) {
    expect(HijriDate::numeric(riyadhNoon($gregorian)))->toBe($hijri);
})->with([
    // Anchors announced by Saudi Arabia, which the tabular/civil Islamic
    // calendars miss by one or two days.
    'start of Ramadan 1446' => ['2025-03-01', '1446-09-01'],
    'Eid al-Fitr 1446' => ['2025-03-30', '1446-10-01'],
    'Hijri new year 1447' => ['2025-06-26', '1447-01-01'],
    'start of Ramadan 1447' => ['2026-02-18', '1447-09-01'],
    'reward day, September 2026' => ['2026-09-27', '1448-04-16'],
]);

it('does not fall back to the civil Islamic calendar', function () {
    // islamic-civil would say 1448-04-14 here.
    expect(HijriDate::numeric(riyadhNoon('2026-09-27')))->not->toBe('1448-04-14');
});

it('formats a long Arabic Umm al-Qura date', function () {
    expect(HijriDate::longArabic(riyadhNoon('2026-09-27')))
        ->toContain('١٦')
        ->toContain('١٤٤٨');
});

it('resolves the Hijri day in the given timezone, not UTC', function () {
    // 2026-09-26T22:00Z is already 2026-09-27 in Riyadh.
    $moment = CarbonImmutable::parse('2026-09-26T22:00:00Z');

    expect(HijriDate::numeric($moment, 'Asia/Riyadh'))->toBe('1448-04-16')
        ->and(HijriDate::numeric($moment, 'UTC'))->toBe('1448-04-15');
});
