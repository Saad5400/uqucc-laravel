<?php

namespace App\Helpers;

/**
 * Bidirectional-text fencing for the bot's Arabic messages.
 *
 * A Telegram message has no stylesheet, so direction is decided entirely by
 * the Unicode bidi algorithm reading the characters themselves. In a line like
 * «🥇 Ahmad_99 — 12 نقطة» the medal, the space, the dash and the digits are all
 * neutral or left-to-right, so the algorithm reorders the run and the reader
 * gets the rank, the name and the score in an order nobody wrote — the same
 * line renders differently depending on whether the name happens to be Arabic.
 *
 * Two marks fix it, and they are the only ones used here:
 *  - {@see line()} opens a line with a right-to-left mark, so the paragraph is
 *    unambiguously RTL however it starts (an emoji, a digit, a Latin name).
 *  - {@see isolate()} wraps a value whose script we do not control — a display
 *    name, a username — in a first-strong isolate, so the run picks its own
 *    direction and cannot reorder anything around it.
 *
 * Use {@see ltr()} for a token that must read left-to-right whatever surrounds
 * it, such as «4.» in a ranked list.
 *
 * The marks are zero-width: they change ordering, never the visible text, so
 * a fenced line still contains the plain substrings it is built from.
 */
class Bidi
{
    /** RIGHT-TO-LEFT MARK — an invisible strong RTL character. */
    public const RLM = "\u{200F}";

    /** FIRST STRONG ISOLATE — the run decides its own direction. */
    public const FSI = "\u{2068}";

    /** LEFT-TO-RIGHT ISOLATE — the run reads left-to-right. */
    public const LRI = "\u{2066}";

    /** POP DIRECTIONAL ISOLATE — closes FSI/LRI. */
    public const PDI = "\u{2069}";

    /** Anchor a line as right-to-left, whatever character it starts with. */
    public static function line(string $text): string
    {
        return self::RLM.$text;
    }

    /**
     * Fence a value of unknown script — a member's display name, a username —
     * so it neither reorders nor is reordered by the Arabic around it.
     */
    public static function isolate(string $text): string
    {
        return self::FSI.$text.self::PDI;
    }

    /** Fence a token that must read left-to-right, such as «4.» or a URL. */
    public static function ltr(string $text): string
    {
        return self::LRI.$text.self::PDI;
    }
}
