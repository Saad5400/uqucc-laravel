<?php

use App\Services\Numbers\BaseConversionError;
use App\Services\Numbers\BaseConverter;

function convertNumber(string $number, int $from, int $to): App\Services\Numbers\BaseConversion
{
    return app(BaseConverter::class)->convert($number, $from, $to);
}

it('converts between the common bases', function (string $number, int $from, int $to, string $expected) {
    expect(convertNumber($number, $from, $to)->result)->toBe($expected);
})->with([
    'decimal to binary' => ['255', 10, 2, '11111111'],
    'decimal to hex' => ['687', 10, 16, '2AF'],
    'hex to binary' => ['2AF', 16, 2, '1010101111'],
    'binary to decimal' => ['1010101111', 2, 10, '687'],
    'octal to hex' => ['755', 8, 16, '1ED'],
    'hex to base four' => ['2AF', 16, 4, '22233'],
    'base 36 to decimal' => ['ZZ', 36, 10, '1295'],
    'zero' => ['0', 10, 2, '0'],
    'identical bases' => ['FF', 16, 16, 'FF'],
]);

it('converts fractional numbers exactly', function (string $number, int $from, int $to, string $expected) {
    expect(convertNumber($number, $from, $to)->result)->toBe($expected);
})->with([
    'decimal to binary' => ['13.375', 10, 2, '1101.011'],
    'binary to decimal' => ['1101.011', 2, 10, '13.375'],
    'hex to decimal' => ['0.8', 16, 10, '0.5'],
    'negative' => ['-11.5', 10, 2, '-1011.1'],
]);

it('marks a fraction that does not terminate as approximate', function () {
    $conversion = convertNumber('0.7', 10, 2);

    expect($conversion->isApproximate)->toBeTrue()
        ->and($conversion->result)->toStartWith('≈0.1011001100');
});

it('keeps an exact conversion unmarked', function () {
    expect(convertNumber('0.75', 10, 2)->isApproximate)->toBeFalse();
});

it('reads the input the way a student types it', function (string $number, string $expectedInput, string $expectedResult) {
    $conversion = convertNumber($number, 16, 10);

    expect($conversion->input)->toBe($expectedInput)
        ->and($conversion->result)->toBe($expectedResult);
})->with([
    'lowercase' => ['2af', '2AF', '687'],
    'leading zeros' => ['002AF', '2AF', '687'],
    'hex prefix' => ['0x2AF', '2AF', '687'],
    'spaces' => ['2 AF', '2AF', '687'],
    'arabic-indic digits' => ['١٦', '16', '22'],
    'arabic decimal separator' => ['0٫8', '0.8', '0.5'],
]);

it('shows the positional expansion when the source is not decimal', function () {
    $steps = convertNumber('2AF', 16, 10)->steps;

    expect($steps[0]->title)->toContain('توسيع المنازل')
        ->and($steps[0]->lines)->toContain('A × 16^1 = 10 ×  16 = 160')
        ->and($steps[0]->result)->toBe('الجزء الصحيح بالنظام العشري: 687');
});

it('shows the division ladder when the target is not decimal', function () {
    $steps = convertNumber('687', 10, 16)->steps;

    expect($steps)->toHaveCount(1)
        ->and($steps[0]->title)->toBe('القسمة المتكررة على 16')
        ->and($steps[0]->lines)->toBe([
            '687 ÷ 16 = 42, r = 15 → F',
            ' 42 ÷ 16 =  2, r = 10 → A',
            '  2 ÷ 16 =  0, r =  2 → 2',
        ])
        ->and($steps[0]->result)->toBe('نقرأ البواقي من الأسفل إلى الأعلى: 2AF');
});

it('shows the multiplication ladder for the fractional part', function () {
    $steps = convertNumber('13.375', 10, 2)->steps;

    expect($steps[1]->title)->toBe('الضرب المتكرر في 2')
        ->and($steps[1]->lines)->toBe([
            '0.375 × 2 = 0.75 → 0',
            '0.75  × 2 = 1.5  → 1',
            '0.5   × 2 = 1    → 1',
        ]);
});

it('adds the binary grouping shortcut only when both bases are powers of two', function (int $from, int $to, bool $expected) {
    $titles = array_map(
        fn (App\Services\Numbers\ConversionStep $step): string => $step->title,
        convertNumber('177', $from, $to)->steps,
    );

    expect(in_array('طريقة مختصرة: التجميع الثنائي', $titles, true))->toBe($expected);
})->with([
    'hex to binary' => [16, 2, true],
    'octal to hex' => [8, 16, true],
    'decimal to binary' => [10, 2, false],
    'hex to decimal' => [16, 10, false],
    'octal to base nine' => [8, 9, false],
]);

it('says nothing was needed when the bases are identical', function () {
    $conversion = convertNumber('FF', 16, 16);

    expect($conversion->steps)->toHaveCount(1)
        ->and($conversion->steps[0]->title)->toBe('الأساسان متطابقان')
        ->and($conversion->decimal)->toBe('255');
});

it('summarises the conversion with subscript bases', function () {
    expect(convertNumber('2AF', 16, 2)->summary())->toBe('2AF₁₆ = 1010101111₂');
});

it('exposes the whole working as an array', function () {
    $array = convertNumber('255', 10, 2)->toArray();

    expect($array)->toHaveKeys(['input', 'from_base', 'to_base', 'result', 'decimal', 'is_approximate', 'summary', 'steps'])
        ->and($array['steps'][0])->toHaveKeys(['title', 'lines', 'note', 'result']);
});

it('counts the lines of working', function () {
    expect(convertNumber('255', 10, 2)->lineCount())->toBe(8);
});

it('rejects a base outside 2–36', function (int $from, int $to) {
    convertNumber('10', $from, $to);
})->with([
    'source too small' => [1, 10],
    'source too large' => [37, 10],
    'target too small' => [10, 0],
    'target too large' => [10, 99],
])->throws(BaseConversionError::class, 'يجب أن يكون بين 2 و 36');

it('rejects a digit that does not belong to the base', function () {
    convertNumber('12A', 10, 2);
})->throws(BaseConversionError::class, 'الرمز «A» ليس رقمًا في الأساس 10');

it('rejects an empty number', function () {
    convertNumber('   ', 10, 2);
})->throws(BaseConversionError::class, 'اكتب العدد المراد تحويله أولاً.');

it('rejects more than one decimal point', function () {
    convertNumber('1.2.3', 10, 2);
})->throws(BaseConversionError::class, 'أكثر من فاصلة واحدة');

it('rejects a number too large for exact arithmetic', function () {
    convertNumber(str_repeat('9', 40), 10, 2);
})->throws(BaseConversionError::class, 'العدد كبير جدًا');

it('rejects a fractional part longer than the supported precision', function () {
    convertNumber('0.'.str_repeat('1', BaseConverter::MAX_INPUT_FRACTION_DIGITS + 1), 10, 2);
})->throws(BaseConversionError::class, 'الجزء الكسري أطول من المسموح');
