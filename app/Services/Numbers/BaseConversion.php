<?php

namespace App\Services\Numbers;

/**
 * A completed base conversion, ready for any surface: `toArray()` feeds the
 * web tool's JSON endpoint, `toText()` renders the plain-text working the
 * Telegram bot falls back to when the image cannot be drawn.
 *
 * The steps are the payload, not a bonus — the answer alone is one line, and
 * what the tool exists to show is how it was reached.
 */
final readonly class BaseConversion
{
    /** Subscript digits, index = digit value, for «2AF₁₆» style labels. */
    private const SUBSCRIPT_DIGITS = ['₀', '₁', '₂', '₃', '₄', '₅', '₆', '₇', '₈', '₉'];

    /**
     * @param  string  $input  the canonical rendering of the input (sign, uppercase digits, «.» separator)
     * @param  string  $result  the number written in the target base
     * @param  string  $decimal  the same number in base 10, «≈» prefixed when it had to be cut
     * @param  bool  $isApproximate  whether the fractional part was cut before it terminated
     * @param  list<ConversionStep>  $steps  the working, in order
     */
    public function __construct(
        public string $input,
        public int $fromBase,
        public int $toBase,
        public string $result,
        public string $decimal,
        public bool $isApproximate,
        public array $steps,
    ) {}

    /**
     * The one-line answer, e.g. «2AF₁₆ = 1010101111₂» — the Telegram caption
     * and the headline of every other surface.
     */
    public function summary(): string
    {
        return $this->input.self::subscript($this->fromBase)
            .' = '.$this->result.self::subscript($this->toBase);
    }

    /**
     * A base written as subscript digits, e.g. 16 → «₁₆».
     */
    public static function subscript(int $base): string
    {
        return implode('', array_map(
            static fn (string $digit): string => self::SUBSCRIPT_DIGITS[(int) $digit],
            str_split((string) $base),
        ));
    }

    /**
     * How many machine lines the whole working comes to — what a surface with
     * a size ceiling (a Telegram photo) checks before it tries to draw it.
     */
    public function lineCount(): int
    {
        return array_sum(array_map(
            static fn (ConversionStep $step): int => count($step->lines),
            $this->steps,
        ));
    }

    /**
     * @return array{input: string, from_base: int, to_base: int, result: string, decimal: string, is_approximate: bool, summary: string, steps: list<array{title: string, lines: list<string>, note: string|null, result: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'from_base' => $this->fromBase,
            'to_base' => $this->toBase,
            'result' => $this->result,
            'decimal' => $this->decimal,
            'is_approximate' => $this->isApproximate,
            'summary' => $this->summary(),
            'steps' => array_map(
                static fn (ConversionStep $step): array => $step->toArray(),
                $this->steps,
            ),
        ];
    }

    /**
     * The whole working as plain text: the answer, then every step with its
     * heading, rule, lines and conclusion. For `<pre>`/code-block contexts.
     */
    public function toText(): string
    {
        $blocks = [$this->summary()];

        foreach ($this->steps as $index => $step) {
            $block = [($index + 1).') '.$step->title];

            if ($step->note !== null) {
                $block[] = $step->note;
            }

            foreach ($step->lines as $line) {
                $block[] = '   '.$line;
            }

            if ($step->result !== null) {
                $block[] = $step->result;
            }

            $blocks[] = implode("\n", $block);
        }

        return implode("\n\n", $blocks);
    }
}
