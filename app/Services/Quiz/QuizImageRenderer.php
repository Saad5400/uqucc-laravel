<?php

namespace App\Services\Quiz;

use App\Models\DailyQuiz;
use App\Support\TakumiRenderer;
use Illuminate\Support\Facades\View;

/**
 * Renders one {@see DailyQuiz} to a PNG card — the whole question (preamble,
 * code and the four options) drawn with correct direction, so the group sees a
 * clean image and the Telegram poll below it only needs generic 1–4 choices.
 *
 * The card is a Blade template laid out by the Takumi engine
 * ({@see TakumiRenderer}), not screenshotted by a browser: no Chromium, no
 * page load, no font files to inline, and roughly a second where the browser
 * took several. What that costs is the browser's forgiveness — see
 * resources/views/quiz/question-image.blade.php for the shapes the template
 * keeps to.
 */
class QuizImageRenderer
{
    /** Card width plus the body padding — the fixed image width, in CSS pixels. */
    private const WIDTH = 900;

    /**
     * The card is drawn at twice its CSS size, so the code and the Arabic still
     * read after Telegram's own re-encoding.
     */
    private const SCALE = 2.0;

    /**
     * The shortest the card may come out, in CSS pixels — the height of the
     * viewport the browser used to screenshot full-page, so a one-line question
     * still arrives as a card rather than a strip. It is passed to the renderer
     * rather than written as CSS because the content-derived height is measured
     * from the content, and a `min-height` is not part of that measurement.
     */
    private const MIN_HEIGHT = 600;

    /**
     * The footer's down arrow, pointing at the poll under the image.
     *
     * It was an "⬇️" until the card left the browser behind. Takumi draws only
     * the faces it is given, none of which is an emoji font, so the character
     * would land on whichever vendored face happened to cover U+2B07 — a thin
     * monospace arrow, off-weight next to the Arabic beside it. Drawing it
     * ourselves is both stable and on-palette, and it has to arrive as an image
     * because Takumi has no inline-<svg> parser.
     */
    private const ARROW_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#9aa2ad" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v15"/><path d="M5.5 12.5 12 19l6.5-6.5"/></svg>';

    public function __construct(private readonly TakumiRenderer $takumi = new TakumiRenderer) {}

    /**
     * The rendered card as PNG bytes.
     *
     * The height is deliberately not fixed: a question is anywhere from one
     * line to a screenful of code, and Takumi sizes an image to its content
     * when no height is given, exactly as the full-page screenshot did before
     * it. The template's own `min-height` supplies the floor the browser's
     * viewport used to.
     */
    public function render(DailyQuiz $quiz): string
    {
        $html = View::make('quiz.question-image', [
            'questionHtml' => (string) $quiz->question,
            'options' => array_values($quiz->options ?? []),
            'topic' => $quiz->topic?->name,
            'arrowIcon' => 'data:image/svg+xml;base64,'.base64_encode(self::ARROW_SVG),
        ])->render();

        return $this->takumi->render($html, self::WIDTH, minHeight: self::MIN_HEIGHT, scale: self::SCALE);
    }
}
