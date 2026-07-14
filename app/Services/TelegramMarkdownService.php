<?php

namespace App\Services;

/**
 * Converts the assistant's standard markdown into Telegram-renderable HTML
 * (parse_mode=HTML). Elements Telegram supports natively map to their
 * entities (bold, italic, strikethrough, code, pre, links, blockquotes);
 * elements it cannot render are reformatted for readability instead of
 * being passed through raw: headings become bold lines, tables become
 * labelled bullet rows, list markers become bullets, and horizontal rules
 * are dropped.
 */
class TelegramMarkdownService
{
    /** Placeholder frame using the SUB control character — never in chat text. */
    private const PLACEHOLDER_PREFIX = "\u{1A}TG";

    private const PLACEHOLDER_SUFFIX = "\u{1A}";

    public function toTelegramHtml(string $markdown): string
    {
        $placeholders = [];

        $text = str_replace(["\r\n", "\r"], "\n", trim($markdown));

        $text = $this->extractFencedCode($text, $placeholders);
        $text = $this->extractInlineCode($text, $placeholders);

        $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

        $text = $this->convertBlocks($text);
        $text = $this->convertInline($text, $placeholders);

        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($this->restore($text, $placeholders));
    }

    /**
     * The degraded rendering used when Telegram rejects the HTML: the same
     * restructured text with the entity tags stripped.
     */
    public function toPlainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function extractFencedCode(string $text, array &$placeholders): string
    {
        return (string) preg_replace_callback('/```[a-zA-Z0-9+_.-]*\n?(.*?)```/s', function (array $matches) use (&$placeholders): string {
            $code = htmlspecialchars(trim($matches[1], "\n"), ENT_NOQUOTES, 'UTF-8');

            return $this->claim($placeholders, '<pre>'.$code.'</pre>');
        }, $text);
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function extractInlineCode(string $text, array &$placeholders): string
    {
        return (string) preg_replace_callback('/`([^`\n]+)`/', function (array $matches) use (&$placeholders): string {
            return $this->claim($placeholders, '<code>'.htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8').'</code>');
        }, $text);
    }

    /**
     * Line-oriented structures. Runs on entity-escaped text, so blockquote
     * markers appear as "&gt;".
     */
    private function convertBlocks(string $text): string
    {
        $lines = explode("\n", $text);
        $output = [];
        $index = 0;
        $count = count($lines);

        while ($index < $count) {
            if ($this->isTableRow($lines[$index])) {
                $table = [];

                while ($index < $count && $this->isTableRow($lines[$index])) {
                    $table[] = $lines[$index];
                    $index++;
                }

                $output[] = implode("\n\n", $this->renderTable($table));

                continue;
            }

            if (preg_match('/^&gt;\s?/u', $lines[$index]) === 1) {
                $quote = [];

                while ($index < $count && preg_match('/^&gt;\s?(.*)$/u', $lines[$index], $matches) === 1) {
                    $quote[] = $matches[1];
                    $index++;
                }

                $output[] = '<blockquote>'.trim(implode("\n", $quote)).'</blockquote>';

                continue;
            }

            $output[] = $this->convertBlockLine($lines[$index]);
            $index++;
        }

        return implode("\n", $output);
    }

    private function convertBlockLine(string $line): string
    {
        if (preg_match('/^\s*(?:[-*_]\s*){3,}$/', $line) === 1) {
            return '';
        }

        if (preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/u', $line, $matches) === 1) {
            return '<b>'.$matches[1].'</b>';
        }

        return (string) preg_replace('/^(\s*)[-*+]\s+/u', '$1• ', $line);
    }

    private function isTableRow(string $line): bool
    {
        return preg_match('/^\s*\|.*\|\s*$/', $line) === 1;
    }

    /**
     * A markdown table becomes one bullet line per data row: the first cell
     * bold as the row's key, the rest labelled with their column headers
     * (two-column tables read as plain "key: value").
     *
     * @param  array<int, string>  $rows
     * @return array<int, string>
     */
    private function renderTable(array $rows): array
    {
        $parsed = array_map(function (string $row): array {
            $cells = array_map('trim', explode('|', trim($row)));

            array_shift($cells);
            array_pop($cells);

            return $cells;
        }, $rows);

        $headers = null;

        if (count($parsed) >= 2 && $this->isSeparatorRow($parsed[1])) {
            $headers = $parsed[0];
            $parsed = array_slice($parsed, 2);
        }

        $parsed = array_values(array_filter($parsed, fn (array $cells): bool => ! $this->isSeparatorRow($cells)));

        return array_map(fn (array $cells): string => $this->renderTableRow($cells, $headers), $parsed);
    }

    /**
     * @param  array<int, string>  $cells
     */
    private function isSeparatorRow(array $cells): bool
    {
        if ($cells === []) {
            return false;
        }

        foreach ($cells as $cell) {
            if (preg_match('/^:?-+:?$/', $cell) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $cells
     * @param  array<int, string>|null  $headers
     */
    private function renderTableRow(array $cells, ?array $headers): string
    {
        $first = $cells[0] ?? '';
        $rest = array_slice($cells, 1, preserve_keys: true);

        if (count($cells) === 2) {
            return '• <b>'.$first.'</b>: '.$cells[1];
        }

        $parts = [];

        foreach ($rest as $columnIndex => $cell) {
            if ($cell === '') {
                continue;
            }

            $label = trim($headers[$columnIndex] ?? '');
            $parts[] = $label !== '' ? $label.': '.$cell : $cell;
        }

        return '• <b>'.$first.'</b>'.($parts === [] ? '' : ' — '.implode(' — ', $parts));
    }

    /**
     * Inline entities. Links, images and bare URLs are claimed as
     * placeholders first so emphasis markers inside URLs (underscores,
     * asterisks) are never misread as formatting.
     *
     * @param  array<string, string>  $placeholders
     */
    private function convertInline(string $text, array &$placeholders): string
    {
        $text = (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/u', function (array $matches) use (&$placeholders): string {
            $label = $matches[1] !== '' ? $matches[1] : 'صورة';

            return $this->claim($placeholders, '<a href="'.$this->escapeHref($matches[2]).'">'.$label.'</a>');
        }, $text);

        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+[^)]*)?\)/u', function (array $matches) use (&$placeholders): string {
            return $this->claim($placeholders, '<a href="'.$this->escapeHref($matches[2]).'">'.$matches[1].'</a>');
        }, $text);

        $text = (string) preg_replace_callback('/https?:\/\/[^\s<>()]+/u', function (array $matches) use (&$placeholders): string {
            return $this->claim($placeholders, $matches[0]);
        }, $text);

        $text = (string) preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $text);
        $text = (string) preg_replace('/__(.+?)__/su', '<b>$1</b>', $text);
        $text = (string) preg_replace('/~~(.+?)~~/su', '<s>$1</s>', $text);
        $text = (string) preg_replace('/(?<!\*)\*([^*\n]+?)\*(?!\*)/u', '<i>$1</i>', $text);
        $text = (string) preg_replace('/(?<!_)_([^_\n]+?)_(?!_)/u', '<i>$1</i>', $text);

        return $text;
    }

    private function escapeHref(string $url): string
    {
        return str_replace('"', '%22', $url);
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function claim(array &$placeholders, string $html): string
    {
        $key = self::PLACEHOLDER_PREFIX.count($placeholders).self::PLACEHOLDER_SUFFIX;
        $placeholders[$key] = $html;

        return $key;
    }

    /**
     * Placeholder values can themselves contain earlier placeholder keys
     * (a code span inside a link label), so restoration loops to a fixpoint.
     *
     * @param  array<string, string>  $placeholders
     */
    private function restore(string $text, array $placeholders): string
    {
        do {
            $previous = $text;
            $text = strtr($text, $placeholders);
        } while ($text !== $previous);

        return $text;
    }
}
