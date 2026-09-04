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
