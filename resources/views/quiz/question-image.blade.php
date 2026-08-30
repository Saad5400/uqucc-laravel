{{--
    The daily question rendered as a shareable card, rasterized by
    App\Services\Quiz\QuizImageRenderer through App\Support\TakumiRenderer —
    the Takumi engine lays this markup out directly, so there is no browser and
    no network here. The faces are registered by scripts/takumi-render.mjs
    rather than declared with @font-face; everything else is ordinary CSS and
    this file still opens in a browser as-is, which is how to preview a change.

    Takumi has no inline-<svg> parser and no emoji font is vendored, so the
    footer's down-arrow arrives as a data-URI <img> ($arrowIcon) instead of the
    "⬇️" character it used to be.

    The authored $questionHtml is already sanitized to a small tag vocabulary
    (App\Support\QuizContentHtml), so it is printed unescaped.

    @var string      $questionHtml  Sanitized HTML fragment: the preamble + question.
    @var array       $options       The four plain-text answer options, in order.
    @var string|null $topic         Topic label, shown as a pill; omitted when null.
    @var string      $arrowIcon     data: URI for the footer's down arrow.
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
            --card: #1b1f27;
            --card-border: rgba(255, 255, 255, 0.09);
            --text: #f4f6f8;
            --muted: #9aa2ad;
            --primary: #38a7bb;
            --primary-soft: rgba(56, 167, 187, 0.14);
            --code-bg: #0c1017;
            --code-border: rgba(255, 255, 255, 0.08);
        }

        html {
            font-family: 'IBM Plex Sans Arabic', 'Segoe UI', sans-serif;
        }

        body {
            width: 900px;
            /* Matches the 600px viewport the browser screenshotted full-page:
               the card grows with the question but never comes out shorter.
               This declaration is for the browser preview only — the rendered
               image gets the same floor from QuizImageRenderer::MIN_HEIGHT,
               because Takumi sizes an image from its content and does not read
               a `min-height` while doing it. Change the two together. */
            min-height: 600px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            background:
                radial-gradient(1100px 460px at 82% -12%, rgba(56, 167, 187, 0.16), transparent 60%),
                radial-gradient(900px 500px at 8% 118%, rgba(56, 167, 187, 0.08), transparent 55%),
                var(--bg);
            color: var(--text);
            padding: 48px;
            -webkit-font-smoothing: antialiased;
        }

        .card {
            width: 100%;
            margin: 0 auto;
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: 28px;
            padding: 44px 46px 40px;
            box-shadow: 0 30px 70px -30px rgba(0, 0, 0, 0.7);
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 24px;
            margin-bottom: 30px;
            border-bottom: 1px solid var(--card-border);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 27px;
        }

        .brand-mark {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            background: linear-gradient(140deg, var(--primary), #2b8598);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            box-shadow: 0 8px 20px -8px rgba(56, 167, 187, 0.8);
        }

        .topic {
            font-size: 20px;
            font-weight: 600;
            color: var(--primary);
            background: var(--primary-soft);
            border: 1px solid rgba(56, 167, 187, 0.28);
            padding: 8px 18px;
            border-radius: 999px;
            /* A long topic wraps inside the pill rather than being ellipsized:
               Takumi answers `text-overflow: ellipsis` by drawing the ellipsis
               and nothing else, so a truncating pill would come out empty. The
               names are teacher-written and unbounded, so the width cap stays
               and the second line is the pressure valve. */
            max-width: 380px;
        }

        .content {
            font-size: 30px;
            line-height: 1.95;
            /* 400, not the 500 the browser drew, and deliberately: Takumi lets
               an inline run keep its own weight only while its parent sits at
               the default, so a 500 here would flatten every <strong> in the
               question back into the body text. Emphasis is load-bearing in a
               question; half a weight step of body text is not. */
            font-weight: 400;
        }

        .content p {
            margin-bottom: 16px;
        }

        .content p:last-child {
            margin-bottom: 0;
        }

        .content strong,
        .content b {
            font-weight: 700;
            color: #ffffff;
        }

        .content pre {
            font-family: 'DejaVu Sans Mono', 'IBM Plex Sans Arabic', monospace;
            background: var(--code-bg);
            border: 1px solid var(--code-border);
            border-radius: 16px;
            padding: 20px 24px;
            margin: 22px 0;
            font-size: 25px;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
            direction: ltr;
            text-align: left;
        }

        .content pre code {
            font-family: inherit;
            background: none;
            color: #e7eef5;
        }

        .content code {
            font-family: 'DejaVu Sans Mono', 'IBM Plex Sans Arabic', monospace;
            background: var(--code-bg);
            border: 1px solid var(--code-border);
            border-radius: 7px;
            padding: 2px 8px;
            font-size: 0.86em;
            unicode-bidi: isolate;
            /* Takumi draws inline runs as text, not as boxes, so the chip above
               it survives only in a browser preview. The tint is what actually
               separates an inline `code` from the prose around it in the image
               — and it is the one inline property the engine does honour. */
            color: #a8dbe6;
        }

        .content ul,
        .content ol {
            padding-inline-start: 30px;
            margin-bottom: 16px;
        }

        .options {
            margin-top: 34px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .option {
            display: flex;
            align-items: center;
            gap: 16px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 18px 20px;
            font-size: 25px;
            line-height: 1.45;
            min-height: 74px;
        }

        .option-number {
            flex-shrink: 0;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--primary-soft);
            border: 1px solid rgba(56, 167, 187, 0.4);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 23px;
            font-variant-numeric: tabular-nums;
        }

        .option-text {
            unicode-bidi: plaintext;
            min-width: 0;
        }

        .footer-hint {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .footer-arrow {
            width: 22px;
            height: 22px;
        }

        .footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 34px;
            padding-top: 22px;
            border-top: 1px solid var(--card-border);
            color: var(--muted);
            font-size: 20px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="brand">
                <span class="brand-mark">؟</span>
                <span>سؤال اليوم</span>
            </div>
            @if (filled($topic))
                <span class="topic">{{ $topic }}</span>
            @endif
        </div>

        <div class="content">{!! $questionHtml !!}</div>

        <div class="options">
            @foreach ($options as $index => $option)
                <div class="option">
                    <span class="option-number">{{ $index + 1 }}</span>
                    <span class="option-text">{{ $option }}</span>
                </div>
            @endforeach
        </div>

        <div class="footer">
            <span class="footer-hint">اختر رقم إجابتك في التصويت بالأسفل <img class="footer-arrow" src="{{ $arrowIcon }}" width="22" height="22" alt=""></span>
            <span>دليل طالب كلية الحاسبات · أم القرى</span>
        </div>
    </div>
</body>
</html>
