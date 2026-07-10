import DOMPurify from 'isomorphic-dompurify';

/**
 * Minimal commonmark-ish renderer for the assistant's replies. The model
 * answers in plain prose with light markdown (bold, lists, headings, code),
 * so a tiny hand-rolled converter avoids pulling in a full markdown
 * dependency. All input is HTML-escaped first and the final output is run
 * through DOMPurify, so model output can never inject markup.
 */

const ALLOWED_TAGS = [
    'p',
    'br',
    'strong',
    'em',
    'code',
    'pre',
    'a',
    'ul',
    'ol',
    'li',
    'h2',
    'h3',
    'h4',
    'blockquote',
    'hr',
    'table',
    'thead',
    'tbody',
    'tr',
    'th',
    'td',
];

/** Placeholder sentinel for extracted code spans; a control character never survives user text. */
const CODE_SENTINEL = String.fromCharCode(1);

const CODE_RESTORE_PATTERN = new RegExp(`${CODE_SENTINEL}(\\d+)${CODE_SENTINEL}`, 'g');

const SENTINEL_STRIP_PATTERN = new RegExp(CODE_SENTINEL, 'g');

const escapeHtml = (text: string): string => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const isSafeUrl = (url: string): boolean => /^(https?:\/\/|\/(?!\/)|#)/i.test(url);

/** Inline markdown on already-escaped text: code spans, bold, italic, links. */
const renderInline = (text: string): string => {
    const codeSpans: string[] = [];

    let html = text.replace(/`([^`\n]+)`/g, (_match, code: string) => {
        codeSpans.push(`<code>${code}</code>`);
        return `${CODE_SENTINEL}${codeSpans.length - 1}${CODE_SENTINEL}`;
    });

    html = html
        .replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, (match, label: string, url: string) =>
            isSafeUrl(url) ? `<a href="${url}" target="_blank" rel="noopener noreferrer">${label}</a>` : match,
        )
        .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');

    return html.replace(CODE_RESTORE_PATTERN, (_match, index: string) => codeSpans[Number(index)] ?? '');
};

/** Split one GFM table row into trimmed cells, tolerating optional outer pipes. */
const splitTableRow = (row: string): string[] =>
    row
        .trim()
        .replace(/^\|/, '')
        .replace(/\|$/, '')
        .split('|')
        .map((cell) => cell.trim());

/** A GFM delimiter row: every cell is dashes with optional alignment colons. */
const isTableDelimiter = (line: string): boolean => {
    const cells = splitTableRow(line);

    return cells.length > 0 && cells.every((cell) => /^:?-+:?$/.test(cell));
};

/** Logical text-align for one delimiter cell (start/center/end), honouring RTL. */
const cellAlignment = (spec: string): string => {
    const startColon = spec.startsWith(':');
    const endColon = spec.endsWith(':');

    if (startColon && endColon) {
        return ' style="text-align: center"';
    }

    if (endColon) {
        return ' style="text-align: end"';
    }

    if (startColon) {
        return ' style="text-align: start"';
    }

    return '';
};

const renderTable = (lines: string[]): string => {
    const alignments = splitTableRow(lines[1]).map(cellAlignment);

    const head = splitTableRow(lines[0])
        .map((cell, index) => `<th${alignments[index] ?? ''}>${renderInline(cell)}</th>`)
        .join('');

    const body = lines
        .slice(2)
        .map((line) => {
            const cells = splitTableRow(line)
                .map((cell, index) => `<td${alignments[index] ?? ''}>${renderInline(cell)}</td>`)
                .join('');

            return `<tr>${cells}</tr>`;
        })
        .join('');

    return `<table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
};

const renderBlock = (block: string): string => {
    const lines = block.split('\n');

    const heading = block.match(/^(#{1,6})\s+(.+)$/);
    if (heading && lines.length === 1) {
        const level = Math.min(Math.max(heading[1].length, 2), 4);
        return `<h${level}>${renderInline(heading[2].trim())}</h${level}>`;
    }

    if (lines.length >= 2 && lines[0].includes('|') && isTableDelimiter(lines[1])) {
        return renderTable(lines);
    }

    if (/^([-*_])\s*\1\s*\1[\s\-*_]*$/.test(block.trim())) {
        return '<hr>';
    }

    if (lines.every((line) => /^\s*[-*]\s+/.test(line))) {
        const items = lines.map((line) => `<li>${renderInline(line.replace(/^\s*[-*]\s+/, ''))}</li>`).join('');
        return `<ul>${items}</ul>`;
    }

    if (lines.every((line) => /^\s*\d+[.)]\s+/.test(line))) {
        const items = lines.map((line) => `<li>${renderInline(line.replace(/^\s*\d+[.)]\s+/, ''))}</li>`).join('');
        return `<ol>${items}</ol>`;
    }

    if (lines.every((line) => /^\s*&gt;\s?/.test(line))) {
        const inner = lines.map((line) => renderInline(line.replace(/^\s*&gt;\s?/, ''))).join('<br>');
        return `<blockquote><p>${inner}</p></blockquote>`;
    }

    return `<p>${lines.map(renderInline).join('<br>')}</p>`;
};

export const renderMarkdown = (markdown: string): string => {
    const source = escapeHtml(markdown.replace(/\r\n/g, '\n').replace(SENTINEL_STRIP_PATTERN, ''));

    const segments = source.split(/```([^`]*)```/);
    const html = segments
        .map((segment, index) => {
            if (index % 2 === 1) {
                const code = segment.replace(/^[\w+-]*\n/, '').trimEnd();
                return `<pre><code>${code}</code></pre>`;
            }

            return segment
                .split(/\n{2,}/)
                .map((block) => block.trim())
                .filter((block) => block !== '')
                .map(renderBlock)
                .join('');
        })
        .join('');

    return DOMPurify.sanitize(html, {
        ALLOWED_TAGS,
        ALLOWED_ATTR: ['href', 'target', 'rel', 'style'],
    });
};
