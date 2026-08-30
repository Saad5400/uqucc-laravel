{{--
    The square card the Telegram bot sends above a page's reply. 720 × 720 CSS
    pixels, rendered at 2x by App\Services\OgImageService through
    App\Support\TakumiRenderer.

    It replaces the screenshot of the page the bot used to attach. A screenshot
    of a documentation page is unreadable at Telegram's thumbnail size and the
    reply's caption already carries the page's text, so what the image is for is
    recognition: whose site this is, which section, and which page.

    Centred rather than the wide card's start-aligned layout, because this one is
    seen as a square thumbnail in a busy group before it is ever tapped.

    The engine is not a browser, and the shapes this file keeps to are the ones
    documented on resources/views/quiz/question-image.blade.php — flex only, no
    inline <svg>, no `text-overflow`, and every parent of a bolder run left at
    weight 400. The palette below is shared by hand with social/og-card.blade
    (the wide link-preview card); change the two together.

    Nothing here declares @font-face: scripts/takumi-render.mjs registers the
    faces. The file still opens in a browser as-is, which is how to preview a
    change.

    @var string      $title        Page title, already trimmed to this card's budget.
    @var string|null $section      Parent page's title, drawn as a pill; omitted when null.
    @var string      $description  Two or three sentences, already trimmed.
    @var string      $url          Host and path, no scheme.
    @var string      $logo         data: URI for the site mark.
    @var string      $siteName     The site's own name, drawn under the mark.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg: #0e1116;
            --text: #f4f6f8;
            --muted: #98a1ac;
            --primary: #38a7bb;
            --primary-soft: rgba(56, 167, 187, 0.14);
            --hairline: rgba(255, 255, 255, 0.1);
        }

        html {
            font-family: 'IBM Plex Sans Arabic', 'Segoe UI', sans-serif;
        }

        body {
            width: 720px;
            height: 720px;
            display: flex;
            flex-direction: column;
            background:
                radial-gradient(700px 520px at 50% -12%, rgba(56, 167, 187, 0.26), transparent 62%),
                radial-gradient(620px 460px at 12% 118%, rgba(56, 167, 187, 0.1), transparent 58%),
                var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
        }

        /* The brand edge, the same device the wide card runs down its start
           side, turned along the top: at the size a Telegram thumbnail is
           actually seen, this stripe is the identity and the wordmark under it
           is decoration. */
        .edge {
            height: 8px;
            background: linear-gradient(90deg, rgba(56, 167, 187, 0.15), var(--primary), rgba(56, 167, 187, 0.15));
        }

        .body {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            text-align: center;
            padding: 46px 54px 42px;
        }

        .brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }

        .brand-mark {
            width: 76px;
            height: 76px;
            border-radius: 23px;
            background: linear-gradient(140deg, var(--primary), #22707f);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-name {
            font-size: 22px;
            font-weight: 600;
            color: var(--muted);
            line-height: 1.4;
        }

        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 18px;
            padding: 20px 0;
        }

        .section {
            font-size: 20px;
            font-weight: 600;
            color: var(--primary);
            background: var(--primary-soft);
            border: 1px solid rgba(56, 167, 187, 0.3);
            border-radius: 999px;
            padding: 8px 20px;
            line-height: 1.35;
            /* A long section name wraps inside the pill instead of being
               ellipsized: the engine answers `text-overflow: ellipsis` by
               drawing the ellipsis and nothing else, and trimmed to one line
               (OgImageService::CARDS) so the middle's height stays the sum the
               720px box was budgeted against. */
            max-width: 470px;
        }

        /* 44px over three lines is the tallest this card's middle may grow:
           the brand block, the footer and the worst-case description are
           measured against the 720px box, and OgImageService::CARDS trims the
           title to the 62 characters that fit in those three lines. Change the
           pair together. */
        .title {
            font-size: 44px;
            font-weight: 700;
            line-height: 1.4;
        }

        .description {
            font-size: 21px;
            line-height: 1.7;
            color: var(--muted);
        }

        .footer {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding-top: 20px;
            border-top: 1px solid var(--hairline);
            font-size: 18px;
            color: var(--muted);
        }

        /* The URL is Latin inside an RTL card, so it gets its own block with its
           own direction rather than an inline span — the engine gives an inline
           run no box of its own to reverse. */
        .url {
            display: flex;
            direction: ltr;
            color: var(--primary);
            font-weight: 500;
            font-size: 20px;
        }
    </style>
</head>
<body>
    <div class="edge"></div>

    <div class="body">
        <div class="brand">
            <span class="brand-mark"><img src="{{ $logo }}" width="44" height="44" alt=""></span>
            {{-- The home card's headline IS the site name; printing it in the
                 wordmark as well would say it twice. --}}
            @if ($siteName !== $title)
                <span class="brand-name">{{ $siteName }}</span>
            @endif
        </div>

        <div class="main">
            @if (filled($section))
                <span class="section">{{ $section }}</span>
            @endif

            <div class="title">{{ $title }}</div>
            <div class="description">{{ $description }}</div>
        </div>

        <div class="footer">
            <span class="url">{{ $url }}</span>
            <span>كلية الحاسبات · جامعة أم القرى</span>
        </div>
    </div>
</body>
</html>
