{{--
    The card the Telegram bot sends above a page's reply. 720 CSS pixels wide
    and as tall as the page needs, rendered at 2x by App\Services\OgImageService
    through App\Support\TakumiRenderer.

    It replaces the screenshot of the page the bot used to attach, and it has to
    do the screenshot's actual job: a reader in a group opens this instead of
    the page, so the body below is the page's own content — headings, lists,
    tables, code and images — rewritten into the engine's vocabulary by
    App\Support\SocialCardContent, inside the same frame the wide card uses.

    Height follows the content, exactly as the quiz card's does, which is why
    this template has no `height`. Two things keep that bounded: the character
    and image budgets in OgImageService::CARDS, which decide where the text
    stops and put the «تابع القراءة في الموقع» line at the end when it did, and
    the `max-height` on .content below, which is the backstop for the one thing
    a character count cannot predict — how tall an image turns out to be.

    The `min-height` on .body is what a short page gets: a card that is still a
    poster rather than a strip, with the footer at the bottom where it belongs.
    It is on an inner element on purpose — the engine ignores a `min-height` on
    the root while it measures, so the floor is asked for here AND passed to the
    renderer, and the two have to agree.

    The rest of the engine's shapes are documented on
    resources/views/quiz/question-image.blade.php and on the content partial
    included below. The palette is shared by hand with social/og-card.blade
    (the wide link-preview card); change the two together.

    @var string      $title        Page title, already trimmed to this card's budget.
    @var string|null $section      Parent page's title, drawn as a pill; omitted when null.
    @var string      $description  Fallback prose for a page with no content of its own.
    @var string      $body         The page's content as card markup; empty when the page has none.
    @var bool        $truncated    Whether the body stops short of the page.
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

            /* The content vocabulary at this card's scale. Bigger than the wide
               card's: this one is read, not glanced at, and Telegram scales a
               tall photo down to the width of a phone. */
            --c-size: 22px;
            --c-size-h2: 28px;
            --c-size-h3: 24px;
            --c-size-code: 19px;
            --c-size-small: 19px;
            --c-line: 1.75;
            --c-gap: 14px;
        }

        html {
            font-family: 'IBM Plex Sans Arabic', 'Segoe UI', sans-serif;
        }

        body {
            width: 720px;
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
            gap: 20px;
            padding: 38px 44px 34px;
            /* Matches the floor OgImageService passes the renderer, minus the
               edge above. Change the two together. */
            min-height: 712px;
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
            gap: 13px;
        }

        .brand-mark {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: linear-gradient(140deg, var(--primary), #22707f);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-name {
            font-size: 22px;
            font-weight: 600;
            line-height: 1.35;
            max-width: 330px;
        }

        .section {
            font-size: 19px;
            font-weight: 600;
            color: var(--primary);
            background: var(--primary-soft);
            border: 1px solid rgba(56, 167, 187, 0.3);
            border-radius: 999px;
            padding: 7px 17px;
            line-height: 1.35;
            /* Trimmed to one line rather than wrapped: the engine answers
               `text-overflow: ellipsis` by drawing the ellipsis and nothing
               else. */
            max-width: 300px;
        }

        .title {
            font-size: 40px;
            font-weight: 700;
            line-height: 1.4;
        }

        .content {
            /* grow, never shrink, size from the content: the card's height is
               the content's, and `flex: 1` alone would instead squeeze a long
               page into whatever `min-height` left over. */
            flex: 1 0 auto;
            position: relative;
            display: flex;
            flex-direction: column;
            /* The backstop, not the budget: the text is already cut to size in
               PHP, and this catches the case that cannot be counted — a tall
               image landing at the end. Whatever it clips, the line below has
               already told the reader to go and read the rest. */
            max-height: 2400px;
            overflow: hidden;
        }

        .more {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 19px;
            color: var(--primary);
        }

        .more-rule {
            flex: 1;
            height: 1px;
            background: var(--hairline);
        }

        .footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-top: 18px;
            border-top: 1px solid var(--hairline);
            font-size: 19px;
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
                <span class="brand-mark"><img src="{{ $logo }}" width="31" height="31" alt=""></span>
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
        </div>

        @if ($truncated)
            <div class="more">
                <span>تابع القراءة في الموقع</span>
                <span class="more-rule"></span>
            </div>
        @endif

        <div class="footer">
            <span class="url">{{ $url }}</span>
            <span>كلية الحاسبات · جامعة أم القرى</span>
        </div>
    </div>
</body>
</html>
