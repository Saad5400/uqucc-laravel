<?php

namespace App\Support;

/**
 * Measuring and cutting the HTML the bot sends, the way Telegram will see it.
 *
 * Telegram's message limit counts the characters a reader sees, not the markup
 * that styles them, so a length here is the text with its tags gone and its
 * entities decoded. A cut has to leave the markup well-formed — an unclosed
 * <b> is a message Telegram refuses outright — so it walks tag by tag and
 * closes whatever is still open when the budget runs out.
 */
final class TelegramHtml
{
    /** The character Telegram treats as one, for the visible-length count. */
    private const ELLIPSIS = '…';

    /**
     * Visible characters, as Telegram counts them.
     */
    public static function length(string $html): int
    {
        return mb_strlen(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Whether any visible character survives the markup.
     */
    public static function isBlank(string $html): bool
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '';
    }

    /**
     * Cut the visible text at `$limit` characters, on a word where one is near,
     * with every open tag closed and an ellipsis where the text stopped.
     *
     * Returns the input untouched when it already fits.
     */
    public static function truncate(string $html, int $limit): string
    {
        if (self::length($html) <= $limit) {
            return $html;
        }

        $tokens = preg_split('/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        $out = '';
        $open = [];
        $remaining = max(0, $limit - mb_strlen(self::ELLIPSIS));

        foreach ($tokens as $token) {
            if (str_starts_with($token, '<')) {
                if (preg_match('/^<\/([a-z0-9]+)/i', $token, $match) === 1) {
                    $name = strtolower($match[1]);

                    while ($open !== []) {
                        if (array_pop($open) === $name) {
                            break;
                        }
                    }

                    $out .= $token;
                } elseif (preg_match('/^<([a-z0-9]+)/i', $token, $match) === 1) {
                    $out .= $token;

                    if (! str_ends_with($token, '/>')) {
                        $open[] = strtolower($match[1]);
                    }
                }

                continue;
            }

            $decoded = html_entity_decode($token, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $length = mb_strlen($decoded);

            if ($length <= $remaining) {
                $out .= $token;
                $remaining -= $length;

                continue;
            }

            $cut = mb_substr($decoded, 0, $remaining);
            $lastSpace = mb_strrpos($cut, ' ');

            if ($lastSpace !== false && $lastSpace > $remaining * 0.6) {
                $cut = mb_substr($cut, 0, $lastSpace);
            }

            $out .= htmlspecialchars(rtrim($cut), ENT_NOQUOTES, 'UTF-8').self::ELLIPSIS;
            $remaining = 0;

            break;
        }

        while ($open !== []) {
            $out .= '</'.array_pop($open).'>';
        }

        return $out;
    }
}
