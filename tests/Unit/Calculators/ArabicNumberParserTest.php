<?php

use App\Services\Calculators\ArabicNumberParser;

/*
 * Mirrors resources/js/lib/calculators/parseArabicNumber.test.ts so the PHP
 * and TypeScript implementations provably agree.
 */

it('parses plain latin integers and decimals', function () {
    expect(ArabicNumberParser::parse('12'))->toBe(12.0)
        ->and(ArabicNumberParser::parse('3.5'))->toBe(3.5);
});

it('converts arabic-indic digits to a number', function () {
    expect(ArabicNumberParser::parse('٣٫٥'))->toBe(3.5)
        ->and(ArabicNumberParser::parse('١٢٣'))->toBe(123.0);
});

it('treats arabic comma, decimal separator and latin comma as a decimal point', function () {
    expect(ArabicNumberParser::parse('٣،٥'))->toBe(3.5)
        ->and(ArabicNumberParser::parse('3,5'))->toBe(3.5)
        ->and(ArabicNumberParser::parse('3٫5'))->toBe(3.5);
});

it('trims surrounding whitespace', function () {
    expect(ArabicNumberParser::parse('  12 '))->toBe(12.0);
});

it('returns 0 for empty, default, and non-numeric input', function () {
    expect(ArabicNumberParser::parse(''))->toBe(0.0)
        ->and(ArabicNumberParser::parse())->toBe(0.0)
        ->and(ArabicNumberParser::parse('abc'))->toBe(0.0);
});
