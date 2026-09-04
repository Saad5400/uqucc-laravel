<?php

namespace App\Services\Numbers;

use App\Support\TakumiRenderer;
use Illuminate\Support\Facades\View;

/**
 * Renders a {@see BaseConversion} to a PNG card — the answer and every step
 * that produced it — for the Telegram bot, where a `<pre>` block of Arabic
 * headings and LTR arithmetic wraps and shreds itself on a phone.
 *
 * The card is a Blade template laid out by the Takumi engine
 * ({@see TakumiRenderer}), the same path the daily quiz card takes: no
 * browser, no page load, a few hundred milliseconds. What that costs is the
 * browser's forgiveness — flexbox only, no inline `<svg>`, no emoji font — so
 * resources/views/tools/base-conversion.blade.php keeps to those shapes.
 */
class BaseConversionImageRenderer
{
    /** Card width plus the body padding — the fixed image width, in CSS pixels. */
    private const WIDTH = 880;

    /** Drawn at twice its CSS size, so the digits survive Telegram's re-encoding. */
    private const SCALE = 2.0;

    /**
     * The shortest the card may come out, in CSS pixels, so a one-step
     * conversion still arrives as a card rather than a strip. It is passed to
     * the renderer rather than written as CSS because Takumi measures the
     * content when it sizes an image and does not read a `min-height`.
     */
    private const MIN_HEIGHT = 420;

    /**
     * The width a step's table has to fill, in CSS pixels: the card's inner
     * width less the indent the tables sit at, which is the template's
     * `margin-inline-start` on `.table`. Change the two together.
     */
    private const TABLE_WIDTH = 672;

    /** Advance of one character in the cells' 20px monospace face. */
    private const MONOSPACE_ADVANCE = 12.2;

    /** Rough advance of one Arabic character in the 17px header face. */
    private const HEADER_ADVANCE = 9.5;

    /** Horizontal padding a cell adds around its content. */
    private const CELL_PADDING = 24;

    public function __construct(private readonly TakumiRenderer $takumi = new TakumiRenderer) {}

    /**
     * The rendered card as PNG bytes. The height follows the working: three
     * division rows or thirty.
     *
     * @throws \RuntimeException when the render fails
     */
    public function render(BaseConversion $conversion): string
    {
        $html = View::make('tools.base-conversion', [
            'conversion' => $conversion,
            'steps' => array_map(
                fn (ConversionStep $step): array => ['step' => $step, 'widths' => $this->columnWidths($step)],
                $conversion->steps,
            ),
            'valueFontSize' => $this->valueFontSize($conversion),
            // Both bases being something other than ten is exactly when the
            // decimal value is worth repeating: it is the bridge the working
            // crossed, and it is nowhere in the headline.
            'showDecimal' => $conversion->fromBase !== 10 && $conversion->toBase !== 10,
            'toolUrl' => route('tools.base-converter'),
        ])->render();

        return $this->takumi->render($html, self::WIDTH, minHeight: self::MIN_HEIGHT, scale: self::SCALE);
    }

    /**
     * The width of each of a step's table columns, in CSS pixels, or an empty
     * list for a step with no table.
     *
     * Takumi lays out flexbox and nothing else — there is no table layout to
     * borrow and no auto-sizing to lean on — so the columns are measured here
     * and written into the markup. The measurement is a character count times
     * the face's advance, which is exact for the monospace cells and near
     * enough for the Arabic headers, and the result is scaled to fill the
     * width the step has: a table that spans its column reads as a table,
     * where one hugging its content reads as a stray list.
     *
     * @return list<int>
     */
    private function columnWidths(ConversionStep $step): array
    {
        if ($step->columns === []) {
            return [];
        }

        $widths = array_map(
            function (string $header, int $column) use ($step): float {
                $cells = array_map(
                    static fn (array $row): int => mb_strlen($row[$column] ?? ''),
                    $step->rows,
                );

                return max(
                    max([0, ...$cells]) * self::MONOSPACE_ADVANCE,
                    mb_strlen($header) * self::HEADER_ADVANCE,
                ) + self::CELL_PADDING;
            },
            $step->columns,
            array_keys($step->columns),
        );

        $scale = self::TABLE_WIDTH / array_sum($widths);

        return array_map(static fn (float $width): int => (int) floor($width * $scale), $widths);
    }

    /**
     * The headline's font size, in CSS pixels, stepped down for numbers long
     * enough to wrap. A 64-bit number in binary is sixty-four digits wide and
     * would otherwise take the whole card.
     */
    private function valueFontSize(BaseConversion $conversion): int
    {
        $longest = max(strlen($conversion->input), strlen($conversion->result));

        return match (true) {
            $longest <= 14 => 46,
            $longest <= 26 => 36,
            $longest <= 44 => 28,
            default => 22,
        };
    }
}
