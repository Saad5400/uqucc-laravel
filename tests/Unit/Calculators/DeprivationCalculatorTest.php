<?php

use App\Services\Calculators\DeprivationCalculator;

/*
 * Mirrors resources/js/lib/calculators/deprivation.test.ts so the PHP and
 * TypeScript implementations provably agree.
 *
 * Course is 17 weeks. For 2 lectures/week:
 *   totalHours = 34, maxUnexcused = floor(34*0.15) = 5, maxAbsence = floor(34*0.25) = 8
 */

it('computes the baseline (no absences, 2 hours/week)', function () {
    $stats = new DeprivationCalculator()->calculate(lecturesPerWeek: 2, unexcusedCount: 0, excusedCount: 0);

    expect($stats->totalHours)->toBe(34)
        ->and($stats->lectureWeight)->toBe(2.94) // round(100/34, 2)
        ->and($stats->unexcusedLeft)->toBe(5) // min(5-0, 8-0)
        ->and($stats->absenceLeft)->toBe(8) // 8-0
        ->and($stats->currentAbsenceRate)->toBe(0.0)
        ->and($stats->isDeprived)->toBeFalse();
});

it('takes the stricter of the unexcused cap and the overall cap', function () {
    // 4 unexcused + 0 excused: byUnexc = 5-4 = 1, byTotal = 8-4 = 4 -> min = 1
    $stats = new DeprivationCalculator()->calculate(lecturesPerWeek: 2, unexcusedCount: 4, excusedCount: 0);

    expect($stats->unexcusedLeft)->toBe(1)
        ->and($stats->absenceLeft)->toBe(4)
        ->and($stats->isDeprived)->toBeFalse();
});

it('lets excused absences eat into the overall cap', function () {
    // 0 unexcused + 6 excused: byUnexc = 5-0 = 5, byTotal = 8-6 = 2 -> unexcusedLeft = 2
    $stats = new DeprivationCalculator()->calculate(lecturesPerWeek: 2, unexcusedCount: 0, excusedCount: 6);

    expect($stats->unexcusedLeft)->toBe(2)
        ->and($stats->absenceLeft)->toBe(2)
        ->and($stats->isDeprived)->toBeFalse();
});

it('flags deprivation when the unexcused cap is exceeded', function () {
    // 6 unexcused: byUnexc = 5-6 = -1 -> deprived
    $stats = new DeprivationCalculator()->calculate(lecturesPerWeek: 2, unexcusedCount: 6, excusedCount: 0);

    expect($stats->unexcusedLeft)->toBe(-1)
        ->and($stats->isDeprived)->toBeTrue();
});

it('flags deprivation when the overall absence cap is exceeded', function () {
    // 0 unexcused + 9 excused: byTotal = 8-9 = -1 -> absenceLeft negative -> deprived
    $stats = new DeprivationCalculator()->calculate(lecturesPerWeek: 2, unexcusedCount: 0, excusedCount: 9);

    expect($stats->absenceLeft)->toBe(-1)
        ->and($stats->isDeprived)->toBeTrue();
});

it('computes the current absence rate to two decimals', function () {
    // 4 unexcused + 4 excused = 8 of 34 -> 23.53%
    $stats = new DeprivationCalculator()->calculate(lecturesPerWeek: 2, unexcusedCount: 4, excusedCount: 4);

    expect($stats->currentAbsenceRate)->toBe(23.53);
});

it('handles a single lecture per week (17 total hours)', function () {
    // totalHours = 17, maxUnexcused = floor(2.55) = 2, maxAbsence = floor(4.25) = 4
    $stats = new DeprivationCalculator()->calculate(lecturesPerWeek: 1, unexcusedCount: 0, excusedCount: 0);

    expect($stats->totalHours)->toBe(17)
        ->and($stats->lectureWeight)->toBe(5.88) // round(100/17, 2)
        ->and($stats->unexcusedLeft)->toBe(2)
        ->and($stats->absenceLeft)->toBe(4);
});
