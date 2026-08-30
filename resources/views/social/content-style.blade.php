{{--
    The page-content vocabulary, shared by both share cards.

    App\Support\SocialCardContent rewrites a page's TipTap document (or its
    legacy HTML) into exactly these `c-*` classes and nothing else, so this file
    and that class are one contract: a class added there needs a rule here, and
    a rule dropped here leaves markup the engine will draw unstyled rather than
    not at all.

    It is included INSIDE each card's own <style>, so the two cards can size the
    same vocabulary differently — every dimension below reads a custom property
    the card sets (--c-size, --c-gap, …), and every colour reads the palette the
    card already defines.

    Engine notes that shaped these rules, all verified by rendering them:
    - an inline element gets no box, so `.c-code` and `.c-link` are colour only;
    - `list-style` draws no marker, so a list item is a flex row that carries
      its own bullet;
    - `<table>` is not a layout, so a table is rows of evenly-shared flex cells
      whose hairlines are 1px gaps with the container's colour showing through;
    - a run loses its own weight when an ancestor sets a non-default one, so
      every block that holds emphasis stays at 400.
--}}
        .c-body {
            display: flex;
            flex-direction: column;
            gap: var(--c-gap);
            min-width: 0;
        }

        .c-p {
            font-size: var(--c-size);
            line-height: var(--c-line);
            /* 400 on purpose: a heavier block here would flatten every <strong>
               inside it back into the body text. */
            font-weight: 400;
        }

        .c-h2 {
            font-size: var(--c-size-h2);
            font-weight: 700;
            line-height: 1.4;
        }

        .c-h3 {
            font-size: var(--c-size-h3);
            font-weight: 600;
            line-height: 1.4;
            color: var(--text);
        }

        .c-li {
            display: flex;
            gap: 10px;
            font-size: var(--c-size);
            line-height: var(--c-line);
            font-weight: 400;
        }

        .c-li-nested {
            padding-inline-start: 24px;
        }

        .c-li-mark {
            color: var(--primary);
            flex-shrink: 0;
        }

        .c-li-text {
            min-width: 0;
        }

        .c-quote {
            display: flex;
            flex-direction: column;
            gap: 6px;
            border-inline-start: 4px solid var(--primary);
            padding-inline-start: 14px;
            color: var(--muted);
        }

        .c-pre {
            font-family: 'DejaVu Sans Mono', 'IBM Plex Sans Arabic', monospace;
            background: var(--code-bg);
            border: 1px solid var(--hairline);
            border-radius: 12px;
            padding: 12px 14px;
            font-size: var(--c-size-code);
            line-height: 1.55;
            white-space: pre-wrap;
            word-break: break-word;
            direction: ltr;
            text-align: left;
            color: #dfe8f0;
        }

        /* Colour only, and deliberately NOT the mono face.
           An inline element gets no box from the engine, so the tint is the
           only thing that separates `code` from the prose around it — and the
           mono face would cost more than it gives here: it covers Arabic but
           does not JOIN it, so an Arabic term in backticks would come out as
           loose disconnected letters. The code BLOCK below keeps the mono
           face, where the content is overwhelmingly Latin. */
        .c-code {
            color: #a8dbe6;
        }

        .c-link {
            color: var(--primary);
        }

        .c-u {
            text-decoration: underline;
        }

        .c-s {
            text-decoration: line-through;
            color: var(--muted);
        }

        .c-hr {
            height: 1px;
            background: var(--hairline);
        }

        .c-figure {
            display: flex;
        }

        .c-figure img {
            border-radius: 12px;
        }

        /* The chip an image that cannot be drawn leaves behind, and the label an
           embed gets: the reader is told something was here rather than shown a
           gap where it used to be. */
        .c-chip {
            align-self: flex-start;
            font-size: var(--c-size-small);
            color: var(--muted);
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--hairline);
            border-radius: 999px;
            padding: 5px 13px;
        }

        .c-table {
            display: flex;
            flex-direction: column;
            gap: 1px;
            background: var(--hairline);
            border: 1px solid var(--hairline);
            border-radius: 12px;
            /* Clips the cells' corners to the container's radius — the engine
               does not do that on its own. */
            overflow: hidden;
        }

        .c-tr {
            display: flex;
            gap: 1px;
        }

        .c-td {
            flex: 1;
            min-width: 0;
            background: var(--bg);
            padding: 9px 11px;
            font-size: var(--c-size-small);
            line-height: 1.5;
        }

        .c-tr-head .c-td {
            background: var(--primary-soft);
            font-weight: 600;
            color: var(--text);
        }

        .c-td-more {
            color: var(--muted);
        }

        .c-alert {
            display: flex;
            flex-direction: column;
            gap: 8px;
            background: var(--primary-soft);
            border: 1px solid rgba(56, 167, 187, 0.28);
            border-radius: 12px;
            padding: 12px 15px;
        }

        .c-collapse {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .c-collapse-q {
            font-size: var(--c-size);
            font-weight: 600;
            line-height: var(--c-line);
        }
