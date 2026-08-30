{{--
    The link preview card — what Twitter, WhatsApp and every chat app draw when
    someone shares a page of the site. 720 × 378 CSS pixels, rendered at 2x by
    App\Services\OgImageService through App\Support\TakumiRenderer.

    It is a designed card, not a screenshot of the page: the engine lays this
    markup out directly, so there is no browser, no page load and no network in
    the request that produces a preview.

    The engine is not a browser, and the shapes this file keeps to are the ones
    documented on resources/views/quiz/question-image.blade.php — flex only, no
    inline <svg>, no `text-overflow`, and every parent of a bolder run left at
    weight 400. The palette below is shared by hand with social/bot-card.blade
    (the square one the Telegram bot sends); change the two together.

    Nothing here declares @font-face: scripts/takumi-render.mjs registers the
    faces. The file still opens in a browser as-is, which is how to preview a
    change — the site serves the same family from resources/css/app.css.

    @var string      $title        Page title, already trimmed to this card's budget.
    @var string|null $section      Parent page's title, drawn as a pill; omitted when null.
    @var string      $description  One or two sentences, already trimmed.
    @var string      $url          Host and path, no scheme.
    @var string      $logo         data: URI for the site mark.
    @var string      $siteName     The site's own name, drawn beside the mark.
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
            height: 378px;
            display: flex;
            background:
                radial-gradient(620px 380px at 88% -20%, rgba(56, 167, 187, 0.22), transparent 62%),
                radial-gradient(520px 340px at 4% 120%, rgba(56, 167, 187, 0.09), transparent 58%),
                var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
        }

        /* The brand edge: a teal rule down the start side (the right, in RTL),
           which is what carries the identity when the card is scaled down to a
           chat-app thumbnail and the wordmark stops being readable. */
        .edge {
            width: 8px;
            background: linear-gradient(180deg, var(--primary), rgba(56, 167, 187, 0.15));
        }

        .body {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 32px 40px 28px;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .brand-mark {
            width: 48px;
            height: 48px;
            border-radius: 15px;
            background: linear-gradient(140deg, var(--primary), #22707f);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-name {
            font-size: 22px;
            font-weight: 600;
            line-height: 1.35;
            max-width: 300px;
        }

        .section {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary);
            background: var(--primary-soft);
            border: 1px solid rgba(56, 167, 187, 0.3);
            border-radius: 999px;
            padding: 7px 16px;
            line-height: 1.35;
            /* A long section name wraps inside the pill instead of being
               ellipsized: the engine answers `text-overflow: ellipsis` by
               drawing the ellipsis and nothing else. It is trimmed to one line
               (OgImageService::CARDS) rather than allowed to wrap: a second
               line here grows the header, and the 378px box has no slack for
               it once the title and the description are at their longest. */
            max-width: 300px;
        }

        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 12px;
            padding: 16px 0;
        }

        /* 38px over two lines is the tallest this card's middle may grow: the
           header, the footer and the worst-case description are measured against
           the 378px box, and OgImageService::CARDS trims the title to the 46
           characters that fit in those two lines. Change the pair together. */
        .title {
            font-size: 38px;
            font-weight: 700;
            line-height: 1.4;
        }

        .description {
            font-size: 19px;
            line-height: 1.6;
            color: var(--muted);
        }

        .footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-top: 16px;
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
        }
    </style>
</head>
<body>
    <div class="edge"></div>

    <div class="body">
        <div class="header">
            <div class="brand">
                <span class="brand-mark"><img src="{{ $logo }}" width="28" height="28" alt=""></span>
                {{-- The home card's headline IS the site name; printing it in the
                 wordmark as well would say it twice. --}}
            @if ($siteName !== $title)
                <span class="brand-name">{{ $siteName }}</span>
            @endif
            </div>

            @if (filled($section))
                <span class="section">{{ $section }}</span>
            @endif
        </div>

        <div class="main">
            <div class="title">{{ $title }}</div>
            <div class="description">{{ $description }}</div>
        </div>

        <div class="footer">
            <span class="url">{{ $url }}</span>
            <span>جامعة أم القرى</span>
        </div>
    </div>
</body>
</html>
