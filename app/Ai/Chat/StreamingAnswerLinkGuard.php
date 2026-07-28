<?php

namespace App\Ai\Chat;

/**
 * {@see AnswerLinkGuard} applied to a live SSE stream. Text deltas arrive a few
 * characters at a time, so a link cannot be judged until it is whole: this
 * holds back only the tail that might still become one, emits everything
 * before it immediately, and releases the link once complete — checked, or
 * demoted to its label.
 *
 * The alternative (stream raw, correct at the end) would flash a fabricated
 * source at the reader before retracting it. Holding a few dozen characters
 * for the handful of milliseconds a link takes to arrive is invisible.
 */
class StreamingAnswerLinkGuard
{
    /** Text held back because it may still turn into a link. */
    private string $held = '';

    /**
     * Give up holding after this many characters — a "[" that never closes is
     * ordinary prose, and no real link in this corpus comes close.
     */
    private const MAX_HELD = 300;

    public function __construct(private readonly AnswerLinkGuard $guard) {}

    /**
     * Feed one delta; returns the text that is safe to emit right now.
     */
    public function push(string $delta): string
    {
        $this->held .= $delta;
        $safe = '';

        while ($this->held !== '') {
            $start = $this->nextCandidate($this->held);

            if ($start === null) {
                $safe .= $this->held;
                $this->held = '';

                break;
            }

            $safe .= substr($this->held, 0, $start);
            $rest = substr($this->held, $start);

            if (preg_match('/^\[[^\]\[]*\]\(\s*[^()\s]+\s*\)/', $rest, $matches) === 1) {
                $safe .= $this->guard->sanitize($matches[0]);
                $this->held = substr($rest, strlen($matches[0]));

                continue;
            }

            if (preg_match('/^https?:\/\/[^\s<>()\[\]"\']+(?=\s)/', $rest, $matches) === 1) {
                $safe .= $this->guard->sanitize($matches[0]);
                $this->held = substr($rest, strlen($matches[0]));

                continue;
            }

            if ($this->mayStillComplete($rest)) {
                $this->held = $rest;

                break;
            }

            $safe .= substr($rest, 0, 1);
            $this->held = substr($rest, 1);
        }

        return $safe;
    }

    /**
     * Release whatever is still held — the stream is over, so an unfinished
     * candidate is just text (sanitized anyway, in case it is a bare URL that
     * ended with the message rather than with whitespace).
     */
    public function flush(): string
    {
        $remaining = $this->guard->sanitize($this->held);
        $this->held = '';

        return $remaining;
    }

    /**
     * Offset of the next "[" or "http" — the only two ways a link can start.
     */
    private function nextCandidate(string $text): ?int
    {
        $offsets = array_filter(
            [strpos($text, '['), strpos($text, 'http')],
            static fn (int|false $offset): bool => $offset !== false,
        );

        return $offsets === [] ? null : min($offsets);
    }

    /**
     * Whether the held tail is a plausible prefix of a link that simply has
     * not finished arriving. A newline ends the possibility — no link in this
     * output spans lines — and so does running past the hold budget.
     */
    private function mayStillComplete(string $rest): bool
    {
        if (strlen($rest) > self::MAX_HELD || str_contains($rest, "\n")) {
            return false;
        }

        if (str_starts_with($rest, '[')) {
            return preg_match('/^\[[^\]\[]*(\](\(\s*[^()\s]*)?)?$/', $rest) === 1;
        }

        return preg_match('/^h(t(t(p(s?(:(\/(\/[^\s<>()\[\]"\']*)?)?)?)?)?)?$/', $rest) === 1;
    }
}
