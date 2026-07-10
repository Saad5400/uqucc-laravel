<?php

use App\Services\Calculators\TransferCalculator;

/*
 * Mirrors resources/js/lib/calculators/transfer.test.ts so the PHP and
 * TypeScript implementations provably agree.
 */

it('computes the default 50/50 composite score', function () {
    // weighted 80 -> 80 * (50/100) = 40
    // gpa 4 -> 4 * (50/4) = 50
    // total = 90
    expect(new TransferCalculator()->calculate('80', '4'))->toBe(90.0);
});

it('uses a custom percentage split', function () {
    // 70/30 split: weighted 80 -> 80 * 0.7 = 56 ; gpa 4 -> 4 * (30/4) = 30 ; total = 86
    expect(new TransferCalculator()->calculate('80', '4', '70', '30'))->toBe(86.0);
});

it('returns null when the weighted score is <= 0', function () {
    expect(new TransferCalculator()->calculate('0', '4'))->toBeNull()
        ->and(new TransferCalculator()->calculate('-5', '4'))->toBeNull();
});

it('returns null when the cumulative GPA is <= 0', function () {
    expect(new TransferCalculator()->calculate('80', '0'))->toBeNull()
        ->and(new TransferCalculator()->calculate('80', '-1'))->toBeNull();
});

it('returns null for empty / non-numeric input (parses to 0)', function () {
    expect(new TransferCalculator()->calculate('', ''))->toBeNull()
        ->and(new TransferCalculator()->calculate('abc', 'xyz'))->toBeNull();
});

it('supports arabic-indic numerals in all fields', function () {
    // weighted ٨٠ = 80, gpa ٤ = 4, default split -> 90
    expect(new TransferCalculator()->calculate('٨٠', '٤'))->toBe(90.0);
});

it('handles fractional weighted score and GPA', function () {
    // weighted 75.5 -> 37.75 ; gpa 3.5 -> 3.5 * 12.5 = 43.75 ; total = 81.5
    expect(new TransferCalculator()->calculate('75.5', '3.5'))->toBe(81.5);
});
