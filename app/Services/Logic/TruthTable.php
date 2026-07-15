<?php

namespace App\Services\Logic;

/**
 * A generated truth table, ready for any surface: `toArray()` feeds the web
 * tool's JSON endpoint, `toTextTable()` renders the monospace table the
 * Telegram bot and the AI assistant tool reply with.
 *
 * Columns are the variables (first-appearance order) followed by every
 * distinct compound subformula, innermost first, the full formula last.
 * Rows enumerate assignments with the first variable's T block on top.
 */
final readonly class TruthTable
{
    /**
     * @param  string  $formula  the canonical rendering (¬ ∧ ∨ → ↔) of the input
     * @param  list<string>  $variables
     * @param  list<string>  $columns  column labels, variables first
     * @param  list<list<bool>>  $rows  one value per column, per row
     */
    public function __construct(
        public string $formula,
        public array $variables,
        public array $columns,
        public array $rows,
    ) {}

    /**
     * Whether the formula is a tautology — true on every row.
     */
    public function isTautology(): bool
    {
        return array_all($this->rows, fn (array $row): bool => end($row) === true);
    }

    /**
     * Whether the formula is a contradiction — false on every row.
     */
    public function isContradiction(): bool
    {
        return array_all($this->rows, fn (array $row): bool => end($row) === false);
    }

    /**
     * One bilingual sentence classifying the formula — tautology,
     * contradiction, or contingent. Shared verbatim by the Telegram bot and
     * the AI assistant tool.
     */
    public function verdict(): string
    {
        return match (true) {
            $this->isTautology() => 'الصيغة تحصيل حاصل (tautology): صادقة في كل الحالات.',
            $this->isContradiction() => 'الصيغة تناقض (contradiction): كاذبة في كل الحالات.',
            default => 'الصيغة ممكنة (contingent): تصدق في بعض الحالات وتكذب في أخرى.',
        };
    }

    /**
     * @return array{formula: string, variables: list<string>, columns: list<string>, rows: list<list<bool>>, is_tautology: bool, is_contradiction: bool}
     */
    public function toArray(): array
    {
        return [
            'formula' => $this->formula,
            'variables' => $this->variables,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'is_tautology' => $this->isTautology(),
            'is_contradiction' => $this->isContradiction(),
        ];
    }

    /**
     * Monospace text rendering with T/F cells centered under each column
     * header — for `<pre>`/code-block contexts (Telegram, AI replies).
     */
    public function toTextTable(): string
    {
        $widths = array_map(fn (string $label): int => max(mb_strlen($label), 1), $this->columns);

        $header = implode(' | ', array_map(
            fn (string $label, int $width): string => $this->center($label, $width),
            $this->columns,
            $widths,
        ));

        $separator = implode('-+-', array_map(
            fn (int $width): string => str_repeat('-', $width),
            $widths,
        ));

        $lines = [$header, $separator];

        foreach ($this->rows as $row) {
            $lines[] = implode(' | ', array_map(
                fn (bool $value, int $width): string => $this->center($value ? 'T' : 'F', $width),
                $row,
                $widths,
            ));
        }

        return implode("\n", $lines);
    }

    private function center(string $text, int $width): string
    {
        $padding = max(0, $width - mb_strlen($text));
        $left = intdiv($padding, 2);

        return str_repeat(' ', $left).$text.str_repeat(' ', $padding - $left);
    }
}
