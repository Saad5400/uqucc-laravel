{{--
    The link preview card — what Twitter, WhatsApp and every chat app draw when
    someone shares a page of the site. 720 × 378 CSS pixels, rendered at 2x by
    App\Services\OgImageService through App\Support\TakumiRenderer.

    It is a designed card, not a screenshot of the page: the engine lays this
    markup out directly, so there is no browser, no page load and no network in
    the request that produces a preview. What it carries IS the page, though —
    the body below is the page's own content, rewritten into the engine's
    vocabulary by App\Support\SocialCardContent.

    This card is 378 pixels tall and the content will not fit, which is the
    point: the content box clips and fades into the ground, so the cut reads as
    a deliberate edge rather than as a sentence that stopped. The engine really
    does honour `overflow: hidden` and an absolutely-positioned gradient — both
    verified — so the budget in OgImageService::CARDS decides how much is worth
    drawing, not how much is safe to draw.

    The rest of the engine's shapes are documented on
    resources/views/quiz/question-image.blade.php and on the content partial
    included below. The palette is shared by hand with quiz/question-image.blade
    (the quiz card the Telegram bot posts); change the two together.

    Nothing here declares @font-face: scripts/takumi-render.mjs registers the
    faces. The file still opens in a browser as-is, which is how to preview a
    change — the site serves the same family from resources/css/app.css.

    @var string      $title        Page title, already trimmed to this card's budget.
    @var string|null $section      Parent page's title, drawn as a pill; omitted when null.
    @var string      $description  Fallback prose for a page with no content of its own.
    @var string      $body         The page's content as card markup; empty when the page has none.
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
            --code-bg: #0c1017;

            /* The content vocabulary at this card's scale — it has four or five
               lines to say something in, so the body sits close to the smallest
               size that still reads in a timeline thumbnail. */
            --c-size: 17px;
            --c-size-h2: 20px;
            --c-size-h3: 18px;
            --c-size-code: 15px;
            --c-size-small: 15px;
            --c-line: 1.65;
            --c-gap: 9px;
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
            gap: 12px;
            padding: 28px 38px 24px;
            min-width: 0;
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
            gap: 12px;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 13px;
            background: linear-gradient(140deg, var(--primary), #22707f);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-name {
            font-size: 19px;
            font-weight: 600;
            line-height: 1.35;
            max-width: 260px;
        }

        .section {
            font-size: 16px;
            font-weight: 600;
            color: var(--primary);
            background: var(--primary-soft);
            border: 1px solid rgba(56, 167, 187, 0.3);
            border-radius: 999px;
            padding: 6px 14px;
            line-height: 1.35;
            /* A long section name is trimmed to one line rather than wrapped:
               the engine answers `text-overflow: ellipsis` by drawing the
               ellipsis and nothing else, and a second line here grows the header
               into the content's room. */
            max-width: 270px;
        }

        /* Dominant, and capped at two lines by the budget in
           OgImageService::CARDS — the content below gets whatever is left. */
        .title {
            font-size: 31px;
            font-weight: 700;
            line-height: 1.35;
        }

        .content {
            position: relative;
            flex: 1;
            overflow: hidden;
            min-height: 0;
        }

        /* The cut. It has to be absolute — a gradient in the flow would push the
           content up instead of lying over it — and it fades to the ground
           colour, so the last visible line dissolves rather than being sliced. */
        .fade {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 46px;
            background: linear-gradient(to top, var(--bg), rgba(14, 17, 22, 0));
        }

        .footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-top: 12px;
            border-top: 1px solid var(--hairline);
            font-size: 16px;
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

@include('social.content-style')
    </style>
</head>
<body>
    <div class="edge"></div>

    <div class="body">
        <div class="header">
            <div class="brand">
                <span class="brand-mark"><img src="{{ $logo }}" width="25" height="25" alt=""></span>
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

        <div class="title">{{ $title }}</div>

        <div class="content">
            @if (filled($body))
                <div class="c-body">{!! $body !!}</div>
            @else
                <div class="c-body"><div class="c-p">{{ $description }}</div></div>
            @endif

            <div class="fade"></div>
        </div>

        <div class="footer">
            <span class="url">{{ $url }}</span>
            <span>جامعة أم القرى</span>
        </div>
    </div>
</body>
</html>
