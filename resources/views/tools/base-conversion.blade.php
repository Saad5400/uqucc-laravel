{{--
    A base conversion rendered as a card — the answer, then every step that
    produced it — rasterized by App\Services\Numbers\BaseConversionImageRenderer
    through App\Support\TakumiRenderer. The Takumi engine lays this markup out
    directly, so there is no browser and no network here. The faces are
    registered by scripts/takumi-render.mjs rather than declared with
    @font-face; everything else is ordinary CSS and this file still opens in a
    browser as-is, which is how to preview a change.

    Two rules the engine imposes, both load-bearing below: no inline <svg> and
    no emoji font (the brand mark is the text "01", not a glyph), and flexbox
    only — every box here is a flex container with an explicit size.

    The direction split is the whole design: Arabic headings and notes flow
    RTL, while the arithmetic is machine text and sits in `dir="ltr"`
    monospace islands (docs/ux-principles.md).

    Each step draws itself as what it is rather than as a paragraph of
    arithmetic: a ladder is a table with its digit column tinted, and the
    bit-grouping shortcut is each digit sitting on top of its own bits. The
    monospace `lines` the steps also carry are for text-only surfaces (the
    bot's fallback reply); nothing here uses them except the rare step that
    has no table at all.

    @var \App\Services\Numbers\BaseConversion $conversion    The conversion and its steps.
    @var array                                $steps         One entry per step: ['step' => ConversionStep, 'widths' => list<int>].
    @var int                                  $valueFontSize Headline size in px, stepped down for long numbers.
    @var bool                                 $showDecimal   Whether to repeat the base-10 value under the headline.
    @var string                               $toolUrl       The web tool, printed in the footer.
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
            --row-border: rgba(255, 255, 255, 0.06);
        }

        html {
            font-family: 'IBM Plex Sans Arabic', 'Segoe UI', sans-serif;
        }

        body {
            width: 880px;
            /* The floor the renderer also passes as `minHeight`, because Takumi
               sizes an image from its content and does not read a `min-height`
               while doing it. Change the two together. */
            min-height: 420px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            background:
                radial-gradient(1100px 460px at 82% -12%, rgba(56, 167, 187, 0.16), transparent 60%),
                radial-gradient(900px 500px at 8% 118%, rgba(56, 167, 187, 0.08), transparent 55%),
                var(--bg);
            color: var(--text);
            padding: 40px;
            -webkit-font-smoothing: antialiased;
        }

        .card {
            width: 100%;
            display: flex;
            flex-direction: column;
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: 26px;
            padding: 36px 38px 30px;
            box-shadow: 0 30px 70px -30px rgba(0, 0, 0, 0.7);
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 22px;
            border-bottom: 1px solid var(--card-border);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 26px;
        }

        .brand-mark {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            background: linear-gradient(140deg, var(--primary), #2b8598);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 22px;
            font-weight: 700;
            box-shadow: 0 8px 20px -8px rgba(56, 167, 187, 0.8);
        }

        .route {
            display: flex;
            align-items: center;
            font-size: 19px;
            font-weight: 600;
            color: var(--primary);
            background: var(--primary-soft);
            border: 1px solid rgba(56, 167, 187, 0.28);
            padding: 8px 18px;
            border-radius: 999px;
        }

        .answer {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            padding: 30px 20px 26px;
        }

        .equation {
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 14px;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: {{ $valueFontSize }}px;
            font-weight: 700;
            line-height: 1.35;
        }

        .term {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 8px;
            /* Long numbers wrap inside their own term instead of pushing the
               equals sign off the card. */
            max-width: 640px;
            word-break: break-all;
        }

        .term-answer {
            color: var(--primary);
        }

        .base-chip {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 30px;
            padding: 0 8px;
            border-radius: 9px;
            background: var(--code-bg);
            border: 1px solid var(--code-border);
            color: var(--muted);
            font-size: 18px;
            font-weight: 600;
        }

        .equals {
            color: var(--muted);
            font-weight: 400;
        }

        .decimal {
            display: flex;
            font-size: 20px;
            color: var(--muted);
        }

        .steps {
            display: flex;
            flex-direction: column;
            gap: 26px;
            padding-top: 4px;
        }

        .step {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .step-header {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 14px;
        }

        .step-number {
            flex-shrink: 0;
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background: var(--primary-soft);
            border: 1px solid rgba(56, 167, 187, 0.4);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 20px;
        }

        .step-title {
            display: flex;
            font-size: 24px;
            font-weight: 700;
        }

        .step-note {
            display: flex;
            font-size: 20px;
            line-height: 1.75;
            color: var(--muted);
            padding-inline-start: 52px;
        }

        /* The working, as a table. Column widths are computed in PHP
           (BaseConversionImageRenderer::columnWidths) and written inline:
           Takumi has no table layout and no auto-sizing to borrow, so the
           columns line up because every cell in one is told the same width. */
        .table {
            display: flex;
            flex-direction: column;
            margin-inline-start: 52px;
            background: var(--code-bg);
            border: 1px solid var(--code-border);
            border-radius: 14px;
        }

        .row {
            display: flex;
            flex-direction: row;
            border-top: 1px solid var(--row-border);
        }

        .row-head {
            border-top: none;
            background: rgba(255, 255, 255, 0.035);
        }

        .cell {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 11px 12px;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 20px;
            color: #cfe6ec;
        }

        .cell-head {
            font-family: 'IBM Plex Sans Arabic', sans-serif;
            font-size: 17px;
            font-weight: 600;
            color: var(--muted);
            padding: 10px 12px;
        }

        /* The column the answer is read out of. Tinting it is the one thing
           that turns a grid of numbers into a story. The two radii keep the
           tint inside the table's rounded corners — the engine does not clip
           a child to its parent's radius, so the corners are cut here. */
        .cell-key {
            background: var(--primary-soft);
            color: var(--primary);
            font-weight: 700;
        }

        .cell-key-first {
            border-top-right-radius: 13px;
        }

        .cell-key-last {
            border-bottom-right-radius: 13px;
        }

        /* The bit-grouping shortcut: each cell stacked over what it becomes. */
        .strips {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
            margin-inline-start: 52px;
        }

        .strip {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 16px;
        }

        .strip-labels {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .strip-label {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            height: 46px;
            min-width: 118px;
            font-size: 18px;
            color: var(--muted);
        }

        .strip-cells {
            display: flex;
            flex-direction: row;
            gap: 10px;
        }

        .strip-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .chip {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 46px;
            padding: 0 14px;
            border-radius: 12px;
            background: var(--code-bg);
            border: 1px solid var(--code-border);
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 21px;
            color: #cfe6ec;
        }

        .chip-key {
            background: var(--primary-soft);
            border-color: rgba(56, 167, 187, 0.4);
            color: var(--primary);
            font-weight: 700;
        }

        .strip-arrow {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 118px;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 22px;
            color: var(--muted);
        }

        /* The one step with no table of its own (identical bases). */
        .lines {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-inline-start: 52px;
            background: var(--code-bg);
            border: 1px solid var(--code-border);
            border-radius: 14px;
            padding: 16px 20px;
        }

        .line {
            display: flex;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 21px;
            line-height: 1.5;
            color: #cfe6ec;
            /* The columns are aligned with spaces (BaseConverter::alignedLines),
               so collapsing whitespace here would undo the alignment. */
            white-space: pre-wrap;
            word-break: break-all;
        }

        .step-result {
            display: flex;
            font-size: 21px;
            font-weight: 600;
            color: var(--primary);
            padding-inline-start: 52px;
        }

        .footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--card-border);
            color: var(--muted);
            font-size: 19px;
            font-weight: 500;
        }

        .footer-url {
            display: flex;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 17px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="brand">
                <span class="brand-mark">01</span>
                <span>تحويل الأعداد</span>
            </div>
            <span class="route">من الأساس {{ $conversion->fromBase }} إلى الأساس {{ $conversion->toBase }}</span>
        </div>

        <div class="answer">
            <div class="equation" dir="ltr">
                <span class="term">
                    <span>{{ $conversion->input }}</span>
                    <span class="base-chip">{{ $conversion->fromBase }}</span>
                </span>
                <span class="equals">=</span>
                <span class="term term-answer">
                    <span>{{ $conversion->result }}</span>
                    <span class="base-chip">{{ $conversion->toBase }}</span>
                </span>
            </div>

            @if ($showDecimal)
                <span class="decimal">بالنظام العشري: <span dir="ltr">{{ $conversion->decimal }}</span></span>
            @endif
        </div>

        <div class="steps">
            @foreach ($steps as ['step' => $step, 'widths' => $widths])
                <div class="step">
                    <div class="step-header">
                        <span class="step-number">{{ $loop->iteration }}</span>
                        <span class="step-title">{{ $step->title }}</span>
                    </div>

                    @if ($step->note)
                        <span class="step-note">{{ $step->note }}</span>
                    @endif

                    @if ($step->columns !== [])
                        <div class="table" dir="ltr">
                            <div class="row row-head">
                                @foreach ($step->columns as $column => $header)
                                    <span
                                        class="cell cell-head @if ($column === $step->keyColumn()) cell-key cell-key-first @endif"
                                        style="width: {{ $widths[$column] }}px"
                                    >{{ $header }}</span>
                                @endforeach
                            </div>

                            @foreach ($step->rows as $row)
                                <div class="row">
                                    @foreach ($row as $column => $cell)
                                        <span
                                            class="cell @if ($column === $step->keyColumn()) cell-key @if ($loop->parent->last) cell-key-last @endif @endif"
                                            style="width: {{ $widths[$column] }}px"
                                        >{{ $cell }}</span>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @elseif ($step->layout === \App\Services\Numbers\ConversionStep::LAYOUT_STRIPS)
                        {{-- The strips come in pairs: a row of cells and what each
                             becomes. The last pair's bottom row is the answer. --}}
                        <div class="strips" dir="ltr">
                            @foreach (array_chunk($step->rows, 2) as $pairIndex => [$top, $bottom])
                                @if ($pairIndex > 0)
                                    <span class="strip-arrow">↓</span>
                                @endif

                                <div class="strip">
                                    <span class="strip-labels">
                                        <span class="strip-label" dir="rtl">{{ $top[0] }}</span>
                                        <span class="strip-label" dir="rtl">{{ $bottom[0] }}</span>
                                    </span>

                                    <span class="strip-cells">
                                        @foreach (array_slice($top, 1) as $index => $cell)
                                            <span class="strip-group">
                                                <span class="chip">{{ $cell }}</span>
                                                <span class="chip @if ($loop->parent->last) chip-key @endif">{{ $bottom[$index + 1] }}</span>
                                            </span>
                                        @endforeach
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="lines" dir="ltr">
                            @foreach ($step->lines as $line)
                                <span class="line">{{ $line }}</span>
                            @endforeach
                        </div>
                    @endif

                    @if ($step->result)
                        <span class="step-result">{{ $step->result }}</span>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="footer">
            <span>دليل طالب كلية الحاسبات · أم القرى</span>
            <span class="footer-url" dir="ltr">{{ $toolUrl }}</span>
        </div>
    </div>
</body>
</html>
