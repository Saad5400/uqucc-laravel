<?php

namespace App\Services\Quiz;

use App\Models\DailyQuiz;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

/**
 * Renders one {@see DailyQuiz} to a PNG card via a headless browser — the whole
 * question (preamble, code and the four options) drawn with correct direction,
 * so the group sees a clean image and the Telegram poll below it only needs
 * generic 1–4 choices. The fonts are inlined as data URIs, so the browser
 * renders the card with no network access and no running app.
 */
class QuizImageRenderer
{
    /** Card width plus the body padding — the fixed image width. */
    private const WIDTH = 900;

    /**
     * The self-hosted faces embedded into the card, as [family, weight, file].
     * IBM Plex Sans Arabic is the site face (Arabic + Latin per weight); DejaVu
     * Sans Mono carries code, matching the truth-table renderer's monospace.
     *
     * @var array<int, array{0: string, 1: int, 2: string}>
     */
    private const FONTS = [
        ['IBM Plex Sans Arabic', 400, 'fonts/ibm-plex/ibm-plex-arabic-400.woff2'],
        ['IBM Plex Sans Arabic', 400, 'fonts/ibm-plex/ibm-plex-latin-400.woff2'],
        ['IBM Plex Sans Arabic', 500, 'fonts/ibm-plex/ibm-plex-arabic-500.woff2'],
        ['IBM Plex Sans Arabic', 500, 'fonts/ibm-plex/ibm-plex-latin-500.woff2'],
        ['IBM Plex Sans Arabic', 600, 'fonts/ibm-plex/ibm-plex-arabic-600.woff2'],
        ['IBM Plex Sans Arabic', 600, 'fonts/ibm-plex/ibm-plex-latin-600.woff2'],
        ['IBM Plex Sans Arabic', 700, 'fonts/ibm-plex/ibm-plex-arabic-700.woff2'],
        ['IBM Plex Sans Arabic', 700, 'fonts/ibm-plex/ibm-plex-latin-700.woff2'],
    ];

    private static ?string $fontFaceCss = null;

    /**
     * The rendered card as PNG bytes.
     */
    public function render(DailyQuiz $quiz): string
    {
        $html = View::make('quiz.question-image', [
            'questionHtml' => (string) $quiz->question,
            'options' => array_values($quiz->options ?? []),
            'topic' => $quiz->topic?->name,
            'fontFaceCss' => $this->fontFaceCss(),
        ])->render();

        return $this->browsershot($html)->screenshot();
    }

    /**
     * A Browsershot instance configured like {@see \App\Services\OgImageService}
     * — the same crash-hardened Chromium flags and the same optional Chrome/Node
     * paths for the Nixpacks deployment — waiting for the inlined fonts to load
     * before capturing the full card.
     */
    private function browsershot(string $html): Browsershot
    {
        $browsershot = Browsershot::html($html)
            ->windowSize(self::WIDTH, 600)
            ->deviceScaleFactor(2)
            ->fullPage()
            ->timeout(60)
            ->dismissDialogs()
            ->setScreenshotType('png')
            ->waitForFunction('document.fonts.ready.then(() => true)');

        if ($chromePath = config('services.browsershot.chrome_path')) {
            $browsershot->setChromePath($chromePath);
        }

        if ($nodeBinary = config('services.browsershot.node_binary')) {
            $browsershot->setNodeBinary($nodeBinary);
        }

        if ($nodeModulesPath = config('services.browsershot.node_modules_path')) {
            $browsershot->setNodeModulePath($nodeModulesPath);
        }

        $browsershot->addChromiumArguments([
            'no-sandbox',
            'disable-setuid-sandbox',
            'disable-dev-shm-usage',
            'disable-gpu',
            'disable-extensions',
            'disable-background-networking',
            'disable-crash-reporter',
            'disable-breakpad',
            'font-render-hinting=none',
            'enable-font-antialiasing',
        ]);

        return $browsershot;
    }

    /**
     * The `@font-face` block for the card, built once and memoised: every face
     * read off disk and inlined as a base64 `data:` URI so the headless browser
     * needs no access to the public font files.
     */
    private function fontFaceCss(): string
    {
        if (self::$fontFaceCss !== null) {
            return self::$fontFaceCss;
        }

        $rules = [$this->monoFontFace()];

        foreach (self::FONTS as [$family, $weight, $path]) {
            $uri = $this->dataUri(public_path($path), 'font/woff2');

            if ($uri === null) {
                continue;
            }

            $rules[] = <<<CSS
                @font-face {
                    font-family: '{$family}';
                    font-style: normal;
                    font-weight: {$weight};
                    font-display: swap;
                    src: url({$uri}) format('woff2');
                }
                CSS;
        }

        return self::$fontFaceCss = implode("\n", array_filter($rules));
    }

    /**
     * The monospace face for code, reusing the bundled DejaVu Sans Mono TTF the
     * truth-table renderer already ships.
     */
    private function monoFontFace(): string
    {
        $uri = $this->dataUri(resource_path('fonts/DejaVuSansMono.ttf'), 'font/ttf');

        if ($uri === null) {
            return '';
        }

        return <<<CSS
            @font-face {
                font-family: 'DejaVu Sans Mono';
                font-style: normal;
                font-weight: 400 700;
                font-display: swap;
                src: url({$uri}) format('truetype');
            }
            CSS;
    }

    private function dataUri(string $path, string $mime): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
