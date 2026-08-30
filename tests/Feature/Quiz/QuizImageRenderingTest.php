<?php

use App\Models\DailyQuiz;
use App\Models\QuizTopic;
use App\Services\Quiz\QuizImageRenderer;
use App\Support\TakumiRenderer;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| The question card, actually rendered
|--------------------------------------------------------------------------
|
| These tests run the real renderer: Blade, the Node entrypoint, the Takumi
| engine and the vendored fonts, end to end, and then look at the pixels that
| come out. Nothing here is stubbed, on purpose — a card is the only thing the
| group can read (the poll under it is a generic "1–4"), and every way this has
| broken before was invisible to a test that only checked that some bytes came
| back: a missing font renders the layout with no text in it, a dropped
| stylesheet renders the text with no layout around it, and both are a
| well-formed PNG.
|
| They need `npm install` to have run. That is deliberate rather than skipped:
| @takumi-rs is a production dependency, and an environment without it cannot
| post a question.
|
*/

/** The card as a GD image, rendered for real. */
function renderCard(DailyQuiz $quiz): GdImage
{
    $image = imagecreatefromstring(app(QuizImageRenderer::class)->render($quiz));

    expect($image)->not->toBeFalse();

    return $image;
}

/**
 * How many of the card's pixels are within `$tolerance` of `$hex`, sampled on a
 * grid so the count is cheap and still representative.
 *
 * @param  string  $hex  Six hex digits, no leading #.
 */
function countPixelsNear(GdImage $image, string $hex, int $tolerance = 24): int
{
    [$wantRed, $wantGreen, $wantBlue] = sscanf($hex, '%2x%2x%2x');

    $found = 0;

    for ($x = 0; $x < imagesx($image); $x += 3) {
        for ($y = 0; $y < imagesy($image); $y += 3) {
            $color = imagecolorat($image, $x, $y);

            if (abs((($color >> 16) & 0xFF) - $wantRed) <= $tolerance
                && abs((($color >> 8) & 0xFF) - $wantGreen) <= $tolerance
                && abs(($color & 0xFF) - $wantBlue) <= $tolerance) {
                $found++;
            }
        }
    }

    return $found;
}

/**
 * How many horizontal runs of ink the image contains — one per connected group
 * of glyphs on a single line of text.
 *
 * This is the one measurement that can tell shaped Arabic from tofu. A missing
 * font is not a blank image: Takumi falls back to a face with no Arabic in it
 * and draws one hollow box per character, which is ink in all the same places
 * and passes every "did anything render" check. But boxes never join, and
 * Arabic letters do — so the run count collapses towards the number of words
 * when the text is shaped, and sits at the number of characters when it is not.
 */
function countInkRuns(GdImage $image, int $threshold = 96): int
{
    $runs = 0;
    $previousHadInk = false;

    for ($x = 0; $x < imagesx($image); $x++) {
        $hasInk = false;

        for ($y = 0; $y < imagesy($image); $y++) {
            if ((imagecolorat($image, $x, $y) & 0xFF) > $threshold) {
                $hasInk = true;

                break;
            }
        }

        if ($hasInk && ! $previousHadInk) {
            $runs++;
        }

        $previousHadInk = $hasInk;
    }

    return $runs;
}

it('shapes Arabic with the vendored face rather than falling back to tofu', function () {
    // Ten characters, nine of them letters, in two words. Shaped by IBM Plex
    // Sans Arabic they join into a handful of runs; drawn as one box per
    // character they would be nine. The gap is the whole assertion — it is
    // structural, not a metric that drifts with a font update.
    $probe = imagecreatefromstring(app(TakumiRenderer::class)->render(
        '<style>body{margin:0;background:#000;display:flex}</style>'
        ."<div style=\"width:600px;height:120px;display:flex;align-items:center;padding:0 20px;direction:rtl;font-family:'IBM Plex Sans Arabic';font-size:60px;color:#fff\">سؤال اليوم</div>",
        600,
        scale: 1.0,
    ));

    expect(countInkRuns($probe))->toBeLessThanOrEqual(7);
});

it('renders the question as a PNG at twice the card width', function () {
    $png = app(QuizImageRenderer::class)->render(DailyQuiz::factory()->withCode()->make());

    // The magic number rather than a mime sniff: Telegram is handed these bytes
    // as a photo, and "some string came back" is what this test exists to rule
    // out.
    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    $image = imagecreatefromstring($png);

    expect(imagesx($image))->toBe(1800)
        ->and(imagesy($image))->toBeGreaterThanOrEqual(1200);
});

it('draws the question, the chrome and the option badges rather than an empty card', function () {
    $quiz = DailyQuiz::factory()->withCode()->make();
    $quiz->setRelation('topic', new QuizTopic(['name' => 'أساسيات البرمجة (جافا وبايثون)']));

    $image = renderCard($quiz);

    // --text on --bg: if the fonts failed to register, the layout still draws
    // and every one of these pixels disappears. This is the shape of the
    // text-less-screenshot failure the browser used to have, and the single
    // most valuable thing here to keep watching.
    expect(countPixelsNear($image, 'f4f6f8'))->toBeGreaterThan(500);

    // --primary: the brand mark, the topic pill and the four numbered badges.
    // Losing the stylesheet leaves the text but takes all of these with it.
    expect(countPixelsNear($image, '38a7bb'))->toBeGreaterThan(200);

    // --code-bg: the panel behind the code block.
    expect(countPixelsNear($image, '0c1017', tolerance: 6))->toBeGreaterThan(200);
});

it('grows the card with the question instead of cropping or padding it', function () {
    $short = renderCard(DailyQuiz::factory()->make([
        'question' => '<p dir="rtl">ما ناتج 1 + 1؟</p>',
    ]));

    $long = renderCard(DailyQuiz::factory()->make([
        'question' => '<p dir="rtl">'.str_repeat('نص طويل يشرح السؤال ويستهلك أسطراً كثيرة. ', 40).'</p>',
    ]));

    // A fixed-height card would render these at the same height, with the long
    // question's tail cut off below the frame.
    expect(imagesy($long))->toBeGreaterThan(imagesy($short) + 1000);

    // ...and the floor still holds under a one-line question.
    expect(imagesy($short))->toBeGreaterThanOrEqual(1200);
});

it('draws what the question actually says', function () {
    $first = app(QuizImageRenderer::class)->render(DailyQuiz::factory()->make([
        'question' => '<p dir="rtl">ما ناتج 1 + 1؟</p>',
        'options' => ['واحد', 'اثنان', 'ثلاثة', 'أربعة'],
    ]));

    $second = app(QuizImageRenderer::class)->render(DailyQuiz::factory()->make([
        'question' => '<p dir="rtl">ما ناتج 2 + 2؟</p>',
        'options' => ['واحد', 'اثنان', 'ثلاثة', 'أربعة'],
    ]));

    // Same layout, same height, different ink: the question and the options
    // reach the image rather than the template being drawn around them.
    expect($first)->not->toBe($second);
});

it('logs and throws when the renderer fails, so the poster never posts a blank question', function () {
    Log::spy();

    // Width zero is rejected by the script rather than by PHP, which is the
    // point: this exercises the failure path through the real process.
    expect(fn (): string => app(TakumiRenderer::class)->render('<div>x</div>', 0))
        ->toThrow(RuntimeException::class);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => str_contains($message, 'Takumi render failed'))
        ->once();
});

it('honours a height floor without capping a taller card', function () {
    $takumi = app(TakumiRenderer::class);

    $floored = imagecreatefromstring($takumi->render(
        '<style>body{margin:0;background:#123}</style><div style="display:flex;width:400px;height:40px"></div>',
        400,
        minHeight: 500,
        scale: 1.0,
    ));

    $natural = imagecreatefromstring($takumi->render(
        '<style>body{margin:0;background:#123}</style><div style="display:flex;width:400px;height:900px"></div>',
        400,
        minHeight: 500,
        scale: 1.0,
    ));

    expect(imagesy($floored))->toBe(500)
        ->and(imagesy($natural))->toBe(900);
});
