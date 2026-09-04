<?php

use App\Services\Numbers\BaseConversionImageRenderer;
use App\Services\Numbers\BaseConverter;

/*
|--------------------------------------------------------------------------
| The conversion card, actually rendered
|--------------------------------------------------------------------------
|
| These tests run the real renderer: Blade, the Node entrypoint, the Takumi
| engine and the vendored fonts, end to end, and then look at the pixels. Like
| the quiz card's suite, nothing here is stubbed on purpose — a card is all the
| bot sends, and every way this breaks (a missing font draws the layout with no
| text in it; a dropped stylesheet draws the text with no layout around it) is
| still a well-formed PNG.
|
| They need `npm install` to have run, deliberately rather than skipped:
| @takumi-rs is a production dependency, and an environment without it cannot
| answer the command at all.
|
*/

/** How many of the card's pixels are within `$tolerance` of `$hex`, sampled on a grid. */
function countConversionCardPixelsNear(GdImage $image, string $hex, int $tolerance = 24): int
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

function renderConversionCard(string $number, int $from, int $to): string
{
    return app(BaseConversionImageRenderer::class)->render(
        app(BaseConverter::class)->convert($number, $from, $to),
    );
}

it('renders the conversion as a PNG at twice the card width', function () {
    $png = renderConversionCard('2AF', 16, 2);

    // The magic number rather than a mime sniff: Telegram is handed these
    // bytes as a photo, and "some string came back" is what this rules out.
    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    $image = imagecreatefromstring($png);

    expect(imagesx($image))->toBe(1760)
        ->and(imagesy($image))->toBeGreaterThanOrEqual(840);
});

it('grows with the working rather than clipping it', function () {
    $short = imagecreatefromstring(renderConversionCard('7', 10, 2));
    $long = imagecreatefromstring(renderConversionCard('2AF', 16, 2));

    expect(imagesy($long))->toBeGreaterThan(imagesy($short));
});

it('draws the text and the chrome rather than an empty card', function () {
    $image = imagecreatefromstring(renderConversionCard('2AF', 16, 2));

    // --text on --card: the layout draws with or without fonts, and every one
    // of these pixels disappears when the faces fail to register.
    expect(countConversionCardPixelsNear($image, 'f4f6f8'))->toBeGreaterThan(400);

    // The teal of the answer and the step numbers — the stylesheet reaching
    // the engine at all.
    expect(countConversionCardPixelsNear($image, '38a7bb'))->toBeGreaterThan(100);

    // The card sits on the page's own dark ground.
    expect(countConversionCardPixelsNear($image, '0e1116', 10))->toBeGreaterThan(100);
});
