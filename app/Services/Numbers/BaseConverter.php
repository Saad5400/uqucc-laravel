<?php

namespace App\Services\Numbers;

use App\Helpers\ArabicPlural;

/**
 * The single source of truth for converting a number between bases 2–36
 * (تحويل الأعداد بين الأنظمة): the web tool and the Telegram bot command both
 * hand a raw number and a pair of bases here and render the same
 * {@see BaseConversion}.
 *
 * The working is the product, not the answer. Every surface could get the
 * digits out of `base_convert()` in one call; what a student is asked for in
 * an exam is the division ladder, the positional expansion behind it, and the
 * binary-grouping shortcut when both bases are powers of two — so the engine
 * records each row as it computes it ({@see ConversionStep}).
 *
 * Arithmetic is exact, and deliberately integer-only: the fractional part is
 * carried as a numerator over base^k rather than as a float, so 0.1₃ round
 * trips instead of drifting. The bounds below are what keeps every product
 * inside a 64-bit int.
 */
class BaseConverter
{
    /** Binary is the smallest positional system worth a table. */
    public const MIN_BASE = 2;

    /** 36 = the ten digits plus the twenty-six letters; there is no 37th digit. */
    public const MAX_BASE = 36;

    /**
     * Fraction digits accepted in the input. The bound is arithmetic rather
     * than taste: the fractional part is an exact numerator over base^k, and
     * 36^10 (≈3.7e15) is the largest denominator that still leaves room to
     * multiply by another base inside a 64-bit int.
     */
    public const MAX_INPUT_FRACTION_DIGITS = 10;

    /** Fraction digits produced. A repeating expansion is cut here. */
    public const MAX_OUTPUT_FRACTION_DIGITS = 12;

    /** Integer digits accepted. Anything longer overflows well before this. */
    public const MAX_INPUT_INTEGER_DIGITS = 64;

    /**
     * How far a decimal value inside a step's working is expanded before we
     * call it non-terminating. Generous, because it is an exactness test and
     * not a display width: 16^-4 alone needs sixteen decimal places to land
     * exactly, and a value that does land is worth printing in full.
     */
    private const MAX_DECIMAL_FRACTION_DIGITS = 20;

    /** How much of a non-terminating decimal is actually printed, after «≈». */
    private const APPROXIMATE_DECIMAL_DIGITS = 6;

    /** Column alignments for {@see alignedLines()}. */
    private const ALIGN_LEFT = 'left';

    private const ALIGN_RIGHT = 'right';

    /** Line the column up on its decimal point rather than on its last digit. */
    private const ALIGN_POINT = 'point';

    /** The digit alphabet, value-ordered: the index is the digit's value. */
    private const DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const ARABIC_INDIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const LATIN_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /** Written for a decimal point on an Arabic keyboard. */
    private const DECIMAL_SEPARATORS = ['٫', '،', ','];

    /** Stripped before parsing: digit grouping, and the marks an RTL editor adds. */
    private const IGNORED_CHARACTERS = [' ', '_', '٬', "\u{00A0}", "\u{200E}", "\u{200F}"];

    /** Literal prefixes a programmer may type, per base. */
    private const BASE_PREFIXES = [2 => '0B', 8 => '0O', 16 => '0X'];

    /**
     * Convert `$number`, written in `$fromBase`, to `$toBase`.
     *
     * @throws BaseConversionError when a base is out of range or the number cannot be read in it
     */
    public function convert(string $number, int $fromBase, int $toBase): BaseConversion
    {
        $this->assertBase($fromBase, 'المصدر');
        $this->assertBase($toBase, 'الهدف');

        [$sign, $integerDigits, $fractionDigits] = $this->parse($number, $fromBase);

        $integerValue = $this->integerValue($integerDigits, $fromBase);
        $numerator = $fractionDigits === '' ? 0 : $this->integerValue($fractionDigits, $fromBase);
        $denominator = $fractionDigits === '' ? 1 : $fromBase ** strlen($fractionDigits);

        $input = $sign.$integerDigits.($fractionDigits === '' ? '' : '.'.$fractionDigits);
        [$decimalText, $decimalIsExact] = $this->decimalParts($integerValue, $numerator, $denominator);

        if ($fromBase === $toBase) {
            return new BaseConversion(
                input: $input,
                fromBase: $fromBase,
                toBase: $toBase,
                result: $input,
                decimal: $this->signed($sign, $decimalText, $decimalIsExact),
                isApproximate: false,
                steps: [$this->identicalBasesStep($input, $fromBase)],
            );
        }

        $steps = [];

        if ($fromBase !== 10) {
            $steps[] = $this->integerExpansionStep($integerDigits, $fromBase, $integerValue);

            if ($fractionDigits !== '') {
                $steps[] = $this->fractionExpansionStep($fractionDigits, $fromBase, $numerator, $denominator);
            }
        }

        if ($toBase === 10) {
            return new BaseConversion(
                input: $input,
                fromBase: $fromBase,
                toBase: $toBase,
                result: $this->signed($sign, $decimalText, $decimalIsExact),
                decimal: $this->signed($sign, $decimalText, $decimalIsExact),
                isApproximate: ! $decimalIsExact,
                steps: $steps,
            );
        }

        [$divisionStep, $resultIntegerDigits] = $this->divisionStep($integerValue, $toBase);
        $steps[] = $divisionStep;

        $resultFractionDigits = '';
        $fractionIsExact = true;

        if ($numerator !== 0) {
            [$multiplicationStep, $resultFractionDigits, $fractionIsExact] = $this->multiplicationStep($numerator, $denominator, $toBase);
            $steps[] = $multiplicationStep;
        }

        $result = $resultIntegerDigits.($resultFractionDigits === '' ? '' : '.'.$resultFractionDigits);

        $shortcut = $numerator === 0
            ? $this->binaryGroupingStep($integerValue, $integerDigits, $fromBase, $toBase, $resultIntegerDigits)
            : null;

        if ($shortcut instanceof ConversionStep) {
            $steps[] = $shortcut;
        }

        return new BaseConversion(
            input: $input,
            fromBase: $fromBase,
            toBase: $toBase,
            result: $this->signed($sign, $result, $fractionIsExact),
            decimal: $this->signed($sign, $decimalText, $decimalIsExact),
            isApproximate: ! $fractionIsExact,
            steps: $steps,
        );
    }

    /**
     * A value with its sign, marked «≈» when it had to be cut short. The
     * marker goes outside the sign, where it reads as "about this number"
     * rather than as part of it.
     */
    private function signed(string $sign, string $value, bool $isExact): string
    {
        return ($isExact ? '' : '≈').$sign.$value;
    }

    /**
     * @throws BaseConversionError
     */
    private function assertBase(int $base, string $role): void
    {
        if ($base < self::MIN_BASE || $base > self::MAX_BASE) {
            throw new BaseConversionError(
                'أساس '.$role.' يجب أن يكون بين '.self::MIN_BASE.' و '.self::MAX_BASE.' (المُعطى: '.$base.'). '
                .'The base must be between '.self::MIN_BASE.' and '.self::MAX_BASE.'.'
            );
        }
    }

    /**
     * Read the written number into a sign and its two digit strings, both
     * canonical: uppercase, no leading zeros before the point and none
     * trailing after it.
     *
     * @return array{0: string, 1: string, 2: string} sign, integer digits, fraction digits
     *
     * @throws BaseConversionError
     */
    private function parse(string $number, int $base): array
    {
        $text = str_replace(self::ARABIC_INDIC_DIGITS, self::LATIN_DIGITS, trim($number));
        $text = str_replace(self::DECIMAL_SEPARATORS, '.', $text);
        $text = strtoupper(str_replace(self::IGNORED_CHARACTERS, '', $text));

        $sign = '';

        if ($text !== '' && in_array($text[0], ['-', '+', '−'], true)) {
            $sign = $text[0] === '+' ? '' : '-';
            $text = substr($text, 1);
        }

        $prefix = self::BASE_PREFIXES[$base] ?? null;

        if ($prefix !== null && strlen($text) > 2 && str_starts_with($text, $prefix)) {
            $text = substr($text, 2);
        }

        if ($text === '' || $text === '.') {
            throw new BaseConversionError('اكتب العدد المراد تحويله أولاً. Provide the number to convert.');
        }

        if (substr_count($text, '.') > 1) {
            throw new BaseConversionError('العدد يحتوي على أكثر من فاصلة واحدة. The number has more than one decimal point.');
        }

        [$integerDigits, $fractionDigits] = array_pad(explode('.', $text, 2), 2, '');

        $this->assertDigitsAreValid($integerDigits.$fractionDigits, $base);

        $integerDigits = ltrim($integerDigits, '0');
        $integerDigits = $integerDigits === '' ? '0' : $integerDigits;
        $fractionDigits = rtrim($fractionDigits, '0');

        if (strlen($integerDigits) > self::MAX_INPUT_INTEGER_DIGITS) {
            throw new BaseConversionError(
                'العدد أطول من المسموح — بحد أقصى '.self::MAX_INPUT_INTEGER_DIGITS.' رقمًا. '
                .'The number is longer than the supported '.self::MAX_INPUT_INTEGER_DIGITS.' digits.'
            );
        }

        if (strlen($fractionDigits) > self::MAX_INPUT_FRACTION_DIGITS) {
            throw new BaseConversionError(
                'الجزء الكسري أطول من المسموح — بحد أقصى '.self::MAX_INPUT_FRACTION_DIGITS.' أرقام بعد الفاصلة. '
                .'At most '.self::MAX_INPUT_FRACTION_DIGITS.' fraction digits are supported.'
            );
        }

        return [$sign, $integerDigits, $fractionDigits];
    }

    /**
     * @throws BaseConversionError when a character is not a digit of this base
     */
    private function assertDigitsAreValid(string $digits, int $base): void
    {
        $allowed = substr(self::DIGITS, 0, $base);

        foreach (str_split($digits) as $digit) {
            if (! str_contains($allowed, $digit)) {
                throw new BaseConversionError(
                    'الرمز «'.$digit.'» ليس رقمًا في الأساس '.$base.' — الأرقام المسموحة: '.$this->allowedDigitsLabel($base).'. '
                    .'"'.$digit.'" is not a digit in base '.$base.'.'
                );
            }
        }
    }

    /**
     * The digits of a base as a readable range, e.g. «0-7» or «0-9 و A-F».
     */
    private function allowedDigitsLabel(int $base): string
    {
        if ($base <= 10) {
            return '0-'.($base - 1);
        }

        return '0-9 و A-'.self::DIGITS[$base - 1];
    }

    /**
     * The digits read as an integer, in the base they were written in.
     *
     * @throws BaseConversionError when the value would not fit in a 64-bit int
     */
    private function integerValue(string $digits, int $base): int
    {
        $value = 0;

        foreach (str_split($digits) as $digit) {
            $digitValue = (int) strpos(self::DIGITS, $digit);

            if ($value > intdiv(PHP_INT_MAX - $digitValue, $base)) {
                throw new BaseConversionError(
                    'العدد كبير جدًا على هذه الأداة — جرّب عددًا أصغر. The number is too large to convert here.'
                );
            }

            $value = $value * $base + $digitValue;
        }

        return $value;
    }

    /**
     * Nothing to do — the two bases are the same.
     */
    private function identicalBasesStep(string $input, int $base): ConversionStep
    {
        return new ConversionStep(
            title: 'الأساسان متطابقان',
            lines: [$input.' = '.$input],
            note: 'الأساس المصدر والهدف كلاهما '.$base.'، فالعدد يبقى كما هو دون أي حساب.',
        );
    }

    /**
     * Positional expansion of the integer part: every digit times its place
     * value, summed — the step that gets the number into base 10, which is
     * where both remaining methods start from.
     */
    private function integerExpansionStep(string $digits, int $base, int $value): ConversionStep
    {
        $rows = [];
        $terms = [];
        $length = strlen($digits);

        foreach (str_split($digits) as $index => $digit) {
            $power = $length - $index - 1;
            $digitValue = (int) strpos(self::DIGITS, $digit);
            $placeValue = $base ** $power;
            $product = $digitValue * $placeValue;

            $rows[] = [$digit, '×', $base.'^'.$power, '=', (string) $digitValue, '×', (string) $placeValue, '=', (string) $product];
            $terms[] = (string) $product;
        }

        $lines = $this->alignedLines($rows, [
            self::ALIGN_RIGHT, self::ALIGN_LEFT, self::ALIGN_LEFT, self::ALIGN_LEFT,
            self::ALIGN_RIGHT, self::ALIGN_LEFT, self::ALIGN_RIGHT, self::ALIGN_LEFT, self::ALIGN_RIGHT,
        ]);

        if (count($terms) > 1) {
            $lines[] = implode(' + ', $terms).' = '.$value;
        }

        return new ConversionStep(
            title: 'توسيع المنازل: من الأساس '.$base.' إلى العشري',
            lines: $lines,
            note: 'نضرب كل رقم في الأساس مرفوعًا لأس منزلته — والمنازل تُعد من الصفر ابتداءً من اليمين — ثم نجمع النواتج.',
            result: 'الجزء الصحيح بالنظام العشري: '.$value,
        );
    }

    /**
     * The same expansion for the digits after the point, where the exponents
     * run negative.
     */
    private function fractionExpansionStep(string $digits, int $base, int $numerator, int $denominator): ConversionStep
    {
        $rows = [];
        $terms = [];

        foreach (str_split($digits) as $index => $digit) {
            $power = $index + 1;
            $digitValue = (int) strpos(self::DIGITS, $digit);
            $placeValue = $base ** $power;
            $product = $this->decimalString(0, $digitValue, $placeValue);

            $rows[] = [
                $digit, '×', $base.'^-'.$power, '=',
                (string) $digitValue, '×', $this->decimalString(0, 1, $placeValue), '=', $product,
            ];

            $terms[] = $product;
        }

        $lines = $this->alignedLines($rows, [
            self::ALIGN_RIGHT, self::ALIGN_LEFT, self::ALIGN_LEFT, self::ALIGN_LEFT,
            self::ALIGN_RIGHT, self::ALIGN_LEFT, self::ALIGN_POINT, self::ALIGN_LEFT, self::ALIGN_POINT,
        ]);
        $sum = $this->decimalString(0, $numerator, $denominator);

        if (count($terms) > 1) {
            $lines[] = implode(' + ', $terms).' = '.$sum;
        }

        return new ConversionStep(
            title: 'الجزء الكسري إلى العشري',
            lines: $lines,
            // The exponents are spelled out in words on purpose: «^-1» inside
            // an Arabic sentence is a bidi trap, and the same notation is
            // right there in the aligned lines below where it belongs.
            note: 'أرقام ما بعد الفاصلة تأخذ أسسًا سالبة: الرقم الأول أُسّه سالب واحد، والذي يليه سالب اثنين، وهكذا.',
            result: 'الجزء الكسري بالنظام العشري: '.$sum,
        );
    }

    /**
     * Repeated division of the integer part by the target base, keeping the
     * remainders — the method every syllabus asks to see.
     *
     * @return array{0: ConversionStep, 1: string} the step, and the digits it produced
     */
    private function divisionStep(int $value, int $base): array
    {
        $rows = [];
        $digits = '';
        $current = $value;

        // A do-while, so that zero still produces the one row (and the one
        // digit) it should rather than an empty ladder.
        do {
            $quotient = intdiv($current, $base);
            $remainder = $current % $base;
            $digit = self::DIGITS[$remainder];

            $rows[] = [(string) $current, '÷', (string) $base, '=', $quotient.',', 'r =', (string) $remainder, '→', $digit];
            $digits = $digit.$digits;
            $current = $quotient;
        } while ($current > 0);

        $lines = $this->alignedLines($rows, [
            self::ALIGN_RIGHT, self::ALIGN_LEFT, self::ALIGN_LEFT, self::ALIGN_LEFT,
            self::ALIGN_RIGHT, self::ALIGN_LEFT, self::ALIGN_RIGHT, self::ALIGN_LEFT, self::ALIGN_LEFT,
        ]);

        return [
            new ConversionStep(
                title: 'القسمة المتكررة على '.$base,
                lines: $lines,
                note: 'نقسم على الأساس مرة بعد مرة ونحتفظ بالباقي (r) من كل قسمة، حتى يصير ناتج القسمة صفرًا.',
                result: 'نقرأ البواقي من الأسفل إلى الأعلى: '.$digits,
            ),
            $digits,
        ];
    }

    /**
     * Repeated multiplication of the decimal fraction by the target base,
     * keeping the integer part of each product.
     *
     * @return array{0: ConversionStep, 1: string, 2: bool} the step, the digits, and whether the expansion terminated
     */
    private function multiplicationStep(int $numerator, int $denominator, int $base): array
    {
        $rows = [];
        $digits = '';
        $value = $numerator;

        while ($value !== 0 && strlen($digits) < self::MAX_OUTPUT_FRACTION_DIGITS) {
            $product = $value * $base;
            $digitValue = intdiv($product, $denominator);
            $remainder = $product % $denominator;
            $digit = self::DIGITS[$digitValue];

            $rows[] = [
                $this->decimalString(0, $value, $denominator), '×', (string) $base, '=',
                $this->decimalString($digitValue, $remainder, $denominator), '→', $digit,
            ];

            $digits .= $digit;
            $value = $remainder;
        }

        $lines = $this->alignedLines($rows, [
            self::ALIGN_POINT, self::ALIGN_LEFT, self::ALIGN_LEFT, self::ALIGN_LEFT,
            self::ALIGN_POINT, self::ALIGN_LEFT, self::ALIGN_LEFT,
        ]);

        $isExact = $value === 0;

        return [
            new ConversionStep(
                title: 'الضرب المتكرر في '.$base,
                lines: $lines,
                note: 'نضرب الكسر في الأساس، ونأخذ الجزء الصحيح من الناتج رقمًا، ثم نكمل بالكسر المتبقي.',
                result: 'نقرأ الأرقام من الأعلى إلى الأسفل: 0.'.$digits
                    .($isExact ? '' : ' (كسر لا ينتهي — وقفنا عند '.ArabicPlural::of(self::MAX_OUTPUT_FRACTION_DIGITS, 'رقم', 'رقمان', 'أرقام', 'واحد').')'),
            ),
            $digits,
            $isExact,
        ];
    }

    /**
     * The shortcut every hex-to-binary question is really testing: when both
     * bases are powers of two, each digit is a fixed number of bits, so the
     * conversion is regrouping bits rather than dividing anything.
     *
     * Null when it does not apply — either base is not a power of two, or the
     * two are the same width.
     */
    private function binaryGroupingStep(int $value, string $sourceDigits, int $fromBase, int $toBase, string $resultDigits): ?ConversionStep
    {
        $fromBits = $this->binaryWidth($fromBase);
        $toBits = $this->binaryWidth($toBase);

        if ($fromBits === null || $toBits === null) {
            return null;
        }

        $rows = [];

        if ($fromBits > 1) {
            $groups = array_map(
                fn (string $digit): string => str_pad(
                    decbin((int) strpos(self::DIGITS, $digit)),
                    $fromBits,
                    '0',
                    STR_PAD_LEFT,
                ),
                str_split($sourceDigits),
            );

            $rows[] = [$sourceDigits, '=', implode(' ', $groups)];
        }

        if ($toBits > 1) {
            $binary = decbin($value);
            $padded = str_pad($binary, (int) (ceil(strlen($binary) / $toBits) * $toBits), '0', STR_PAD_LEFT);
            $chunks = str_split($padded, $toBits);

            // The regrouping line is skipped when it would restate its own
            // left side — one full-width group, as in 1010 → A.
            if (implode(' ', $chunks) !== $binary) {
                $rows[] = [$binary, '=', implode(' ', $chunks)];
            }

            $rows[] = [implode(' ', $chunks), '=', $resultDigits];
        }

        $lines = $this->alignedLines($rows, [self::ALIGN_RIGHT, self::ALIGN_LEFT, self::ALIGN_LEFT]);

        return new ConversionStep(
            title: 'طريقة مختصرة: التجميع الثنائي',
            lines: $lines,
            note: 'كل رقم في الأساس '.$fromBase.' يساوي '.$this->bits($fromBits)
                .'، وكل رقم في الأساس '.$toBase.' يساوي '.$this->bits($toBits)
                .' — فيكفي كتابة العدد بالثنائي ثم إعادة تجميع بتاته دون أي قسمة.',
            result: 'النتيجة: '.$resultDigits,
        );
    }

    /**
     * Lay rows of working out as columns, so the eye can run down the ÷, the
     * = and the digits instead of hunting for them. Every column is padded to
     * its widest cell: symbols keep to the left, whole numbers line up on the
     * right, and a column of decimals lines up on its point — which is the
     * only alignment that lets 0.375, 0.75 and 0.5 read as one column.
     *
     * The padding is spaces in a monospace face, which is what every surface
     * draws these lines in — and why they must be printed with the whitespace
     * preserved (`white-space: pre`/`pre-wrap`, or a `<pre>` block).
     *
     * @param  list<list<string>>  $rows  one list of cells per line
     * @param  list<self::ALIGN_*>  $alignments  one per column
     * @return list<string>
     */
    private function alignedLines(array $rows, array $alignments): array
    {
        $widths = [];

        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                [$whole, $fraction] = $this->splitOnPoint($cell);

                $widths[$column]['whole'] = max($widths[$column]['whole'] ?? 0, mb_strlen($whole));
                $widths[$column]['fraction'] = max($widths[$column]['fraction'] ?? 0, mb_strlen($fraction));
                $widths[$column]['cell'] = max($widths[$column]['cell'] ?? 0, mb_strlen($cell));
            }
        }

        return array_map(
            fn (array $row): string => rtrim(implode(' ', array_map(
                fn (string $cell, int $column): string => $this->pad($cell, $widths[$column], $alignments[$column] ?? self::ALIGN_LEFT),
                $row,
                array_keys($row),
            ))),
            $rows,
        );
    }

    /**
     * One cell padded to its column's width, per that column's alignment.
     *
     * @param  array{whole: int, fraction: int, cell: int}  $width
     */
    private function pad(string $cell, array $width, string $alignment): string
    {
        if ($alignment === self::ALIGN_POINT) {
            [$whole, $fraction] = $this->splitOnPoint($cell);

            return $this->spaces($width['whole'] - mb_strlen($whole)).$whole
                .$fraction.$this->spaces($width['fraction'] - mb_strlen($fraction));
        }

        $padding = $this->spaces($width['cell'] - mb_strlen($cell));

        return $alignment === self::ALIGN_RIGHT ? $padding.$cell : $cell.$padding;
    }

    /**
     * A cell split into what sits before the decimal point and what sits from
     * the point onwards — «1.5» → «1» and «.5», «1» → «1» and «».
     *
     * @return array{0: string, 1: string}
     */
    private function splitOnPoint(string $cell): array
    {
        $point = mb_strpos($cell, '.');

        return $point === false
            ? [$cell, '']
            : [mb_substr($cell, 0, $point), mb_substr($cell, $point)];
    }

    private function spaces(int $count): string
    {
        return str_repeat(' ', max(0, $count));
    }

    /** «بت واحد»، «بتان»، «4 بتات» — the note above reads as Arabic. */
    private function bits(int $count): string
    {
        return ArabicPlural::of($count, 'بت', 'بتان', 'بتات', 'واحد');
    }

    /**
     * How many bits one digit of this base is worth, or null when the base is
     * not a power of two.
     */
    private function binaryWidth(int $base): ?int
    {
        for ($bits = 1; $bits <= 5; $bits++) {
            if (2 ** $bits === $base) {
                return $bits;
            }
        }

        return null;
    }

    /**
     * `$whole` plus `$numerator/$denominator` written in base 10, prefixed
     * with «≈» when the expansion had to be cut. For use inside a step's
     * machine lines, where the marker is part of the text.
     */
    private function decimalString(int $whole, int $numerator, int $denominator): string
    {
        [$text, $isExact] = $this->decimalParts($whole, $numerator, $denominator);

        return ($isExact ? '' : '≈').$text;
    }

    /**
     * The same value, with the «≈» left to the caller.
     *
     * @return array{0: string, 1: bool} the text, and whether it is exact
     */
    private function decimalParts(int $whole, int $numerator, int $denominator): array
    {
        if ($numerator === 0) {
            return [(string) $whole, true];
        }

        [$digits, $isExact] = $this->fractionDigits($numerator, $denominator, 10, self::MAX_DECIMAL_FRACTION_DIGITS);

        // A value that never terminates is cut short for reading rather than
        // for arithmetic — the twenty digits above answered "does it end?",
        // and six are all a line of working can carry once the answer is no.
        if (! $isExact) {
            $digits = substr($digits, 0, self::APPROXIMATE_DECIMAL_DIGITS);
        }

        return [$whole.'.'.$digits, $isExact];
    }

    /**
     * The digits `$numerator/$denominator` expands to in `$base`, by the same
     * repeated multiplication {@see multiplicationStep} shows — but silently,
     * for the values a step's text needs to print.
     *
     * @return array{0: string, 1: bool} the digits, and whether the expansion terminated
     */
    private function fractionDigits(int $numerator, int $denominator, int $base, int $maxDigits): array
    {
        $digits = '';
        $value = $numerator;

        while ($value !== 0 && strlen($digits) < $maxDigits) {
            $product = $value * $base;
            $digits .= self::DIGITS[intdiv($product, $denominator)];
            $value = $product % $denominator;
        }

        return [$digits, $value === 0];
    }
}
