<?php

use App\Services\Calculators\GpaCalculator;

/*
 * Mirrors resources/js/lib/calculators/gpa.test.ts so the PHP and TypeScript
 * implementations provably agree.
 */

it('returns all-zero stats for an empty course list', function () {
    expect(new GpaCalculator()->calculate([])->toArray())->toBe([
        'gpa' => 0.0,
        'approximate_gpa' => 0.0,
        'total_credits' => 0.0,
        'total_points' => 0.0,
    ]);
});

it('computes a simple weighted GPA (A+ over 3cr, B over 3cr -> 3.5)', function () {
    $result = new GpaCalculator()->calculate([
        ['credits' => '3', 'grade' => 'A+'],
        ['credits' => '3', 'grade' => 'B'],
    ]);

    expect($result->toArray())->toBe([
        'gpa' => 3.5,
        'approximate_gpa' => 3.5,
        'total_credits' => 6.0,
        'total_points' => 21.0, // 3*4 + 3*3
    ]);
});

it('rounds gpa to 5 decimals and approximate gpa to 2 decimals', function () {
    // 1cr A+ (4) + 2cr C (2) -> (4 + 4) / 3 = 2.6666...
    $result = new GpaCalculator()->calculate([
        ['credits' => '1', 'grade' => 'A+'],
        ['credits' => '2', 'grade' => 'C'],
    ]);

    expect($result->gpa)->toBe(2.66667)
        ->and($result->approximateGpa)->toBe(2.67)
        ->and($result->totalCredits)->toBe(3.0)
        ->and($result->totalPoints)->toBe(8.0);
});

it('ignores rows missing a grade or with zero/invalid credits', function () {
    $result = new GpaCalculator()->calculate([
        ['credits' => '3', 'grade' => 'A+'], // counted
        ['credits' => '0', 'grade' => 'A+'], // skipped: credits not > 0
        ['credits' => '3'], // skipped: no grade
        ['credits' => '3', 'grade' => 'Z'], // skipped: unknown grade
    ]);

    expect($result->totalCredits)->toBe(3.0)
        ->and($result->totalPoints)->toBe(12.0)
        ->and($result->gpa)->toBe(4.0);
});

it('supports arabic-indic numerals in the credits field', function () {
    $result = new GpaCalculator()->calculate([
        ['credits' => '٣', 'grade' => 'A+'],
        ['credits' => '٣', 'grade' => 'B'],
    ]);

    expect($result->toArray())->toBe([
        'gpa' => 3.5,
        'approximate_gpa' => 3.5,
        'total_credits' => 6.0,
        'total_points' => 21.0,
    ]);
});

it('maps each letter grade to its documented point value', function () {
    expect(GpaCalculator::GRADE_VALUES)->toBe([
        'A+' => 4.0,
        'A' => 3.75,
        'B+' => 3.5,
        'B' => 3.0,
        'C+' => 2.5,
        'C' => 2.0,
        'D+' => 1.5,
        'D' => 1.0,
        'F' => 0.0,
    ]);
});

it('counts an F grade toward credits but contributes zero points', function () {
    $result = new GpaCalculator()->calculate([
        ['credits' => '3', 'grade' => 'A+'],
        ['credits' => '3', 'grade' => 'F'],
    ]);

    expect($result->totalCredits)->toBe(6.0)
        ->and($result->totalPoints)->toBe(12.0)
        ->and($result->gpa)->toBe(2.0);
});
