<?php

namespace App\Services\Numbers;

/**
 * One worked step of a base conversion, carried in the two shapes its
 * surfaces need.
 *
 * `lines` is the step as monospace equations — one row of arithmetic per
 * line, columns padded so they line up. It is what a `<pre>` block wants and
 * all a text-only surface (the bot's fallback reply) can draw.
 *
 * `columns`/`rows` is the same working as a table, which is what the card and
 * the web page draw instead: a division ladder is a table with a remainder
 * column, and showing it as one lets the eye run down the digits rather than
 * parse ten equations. The two are built together from the same numbers
 * ({@see BaseConverter}) rather than derived from each other, because
 * flattening a table into an equation loses the columns and splitting an
 * equation into cells loses the operators.
 *
 * The Arabic prose (`title`, `note`, `result`) flows right-to-left; every cell
 * and line is machine text and is drawn left-to-right in a monospace face —
 * see docs/ux-principles.md on LTR islands.
 */
final readonly class ConversionStep
{
    /** `rows` are table rows, headed by `columns`. */
    public const LAYOUT_TABLE = 'table';

    /**
     * `rows` are strips of chips in pairs — each pair is one transformation
     * drawn as a row of stacked cells, and the first cell of a row is its
     * Arabic label rather than a value. Used by the bit-grouping shortcut,
     * where what teaches is seeing each digit sit above its own bits.
     */
    public const LAYOUT_STRIPS = 'strips';

    /**
     * @param  string  $title  Arabic heading, without a step number — the
     *                         surfaces number the steps themselves.
     * @param  list<string>  $lines  LTR machine lines, one per row of working.
     * @param  list<string>  $columns  Arabic table headers; empty when the step
     *                                 has no table and is drawn as lines.
     * @param  list<list<string>>  $rows  cells, parallel to `lines`.
     * @param  self::LAYOUT_*  $layout  how `rows` are meant to be drawn.
     * @param  string|null  $note  Arabic sentence naming the rule, or null.
     * @param  string|null  $result  Arabic conclusion line, or null.
     */
    public function __construct(
        public string $title,
        public array $lines,
        public array $columns = [],
        public array $rows = [],
        public string $layout = self::LAYOUT_TABLE,
        public ?string $note = null,
        public ?string $result = null,
    ) {}

    /**
     * The last column is the one the answer is read out of — the remainders,
     * the fraction digits, the products. Both rich surfaces tint it.
     */
    public function keyColumn(): ?int
    {
        return $this->columns === [] ? null : count($this->columns) - 1;
    }

    /**
     * @return array{title: string, lines: list<string>, columns: list<string>, rows: list<list<string>>, layout: string, note: string|null, result: string|null}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'lines' => $this->lines,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'layout' => $this->layout,
            'note' => $this->note,
            'result' => $this->result,
        ];
    }
}
