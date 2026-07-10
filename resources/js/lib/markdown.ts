import DOMPurify from 'isomorphic-dompurify';

/**
 * Minimal commonmark-ish renderer for the assistant's replies. The model
 * answers in plain prose with light markdown (bold, lists, headings, code),
 * so a tiny hand-rolled converter avoids pulling in a full markdown
 * dependency. All input is HTML-escaped first and the final output is run
 * through DOMPurify, so model output can never inject markup.
 */

const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'code', 'pre', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'hr'];

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

const renderBlock = (block: string): string => {
    const lines = block.split('\n');

    const heading = block.match(/^(#{1,6})\s+(.+)$/);
    if (heading && lines.length === 1) {
        const level = Math.min(Math.max(heading[1].length, 2), 4);
        return `<h${level}>${renderInline(heading[2].trim())}</h${level}>`;
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
        ALLOWED_ATTR: ['href', 'target', 'rel'],
    });
};
