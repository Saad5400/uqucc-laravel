<?php

namespace App\Ai\Chat;

use App\Models\Corpus\CorpusDocument;
use App\Models\Page;

/**
 * Strips links the model invented. The system prompt already forbids citing a
 * URL that never appeared in a tool result, and the model breaks that rule:
 * asked to compare majors it answered with zero tool calls yet still produced
 * "https://uqucc.sb.sa/alta'rif-bialtakhsusat" — a slug that does not exist.
 * Prompt rules are advisory, so this guard enforces the same rule after the
 * fact, deterministically.
 *
 * Only links on an OWNED host are judged: the site itself plus every host a
 * corpus document's reference URL points at. Those are the hosts the assistant
 * speaks for, so a wrong URL there reads as authoritative and sends a student
 * to a 404. Genuinely external links (a course, a datasheet) are left alone —
 * the assistant is allowed to be helpful about the wider world, and we hold no
 * allowlist that could judge them.
 *
 * An invented link is demoted, not deleted: "[الخطة الدراسية](bad)" becomes
 * "الخطة الدراسية", so the sentence still reads while the false promise of a
 * working source is gone. {@see CitationExtractor} remains the source of the
 * citation strip; it derives from tool results and was never hallucinated.
 */
class AnswerLinkGuard
{
    /** @var array<string, true>|null */
    private ?array $allowed = null;

    /** @var array<string, true>|null */
    private ?array $ownedHosts = null;

    /**
     * Matches a markdown link, or a bare URL that is not part of one. The
     * markdown branch is first so its URL is never re-matched as a bare URL.
     */
    private const LINK_PATTERN = '/\[([^\]\[]*)\]\(\s*([^()\s]+)\s*\)|(https?:\/\/[^\s<>()\[\]"\']+)/u';

    /** Trailing characters a URL at the end of a sentence collects. */
    private const TRAILING_PUNCTUATION = ".,;:!?،؛؟\"'»)";

    public function sanitize(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $sanitized = preg_replace_callback(
            self::LINK_PATTERN,
            function (array $matches): string {
                if (($matches[3] ?? '') !== '') {
                    return $this->sanitizeBareUrl($matches[3]);
                }

                return $this->isAllowed($matches[2]) ? $matches[0] : $matches[1];
            },
            $text,
        );

        // A /u pattern returns null on malformed UTF-8, which a stream tail cut
        // mid-codepoint can be. Losing the text outright would be far worse
        // than passing through a link on the one fragment we cannot parse.
        return $sanitized ?? $text;
    }

    /**
     * A fabricated bare URL is dropped entirely — unlike a markdown link it
     * carries no label worth keeping. Trailing sentence punctuation is put
     * back so the prose does not lose its full stop.
     */
    private function sanitizeBareUrl(string $url): string
    {
        $trimmed = rtrim($url, self::TRAILING_PUNCTUATION);
        $punctuation = substr($url, strlen($trimmed));

        return $this->isAllowed($trimmed) ? $url : $punctuation;
    }

    /**
     * A link passes when it is not ours to judge (an external host) or when it
     * resolves to a real AI-visible page or a real document reference URL.
     */
    public function isAllowed(string $url): bool
    {
        $normalized = $this->normalize($url);

        if ($normalized === null) {
            return true;
        }

        if (isset($this->allowedUrls()[$normalized])) {
            return true;
        }

        return ! isset($this->hosts()[$this->hostOf($normalized)]);
    }

    /**
     * The host of a normalized "host/path" string. It carries no scheme, which
     * parse_url would read as a relative path, so one is put back first.
     */
    private function hostOf(string $normalized): string
    {
        $host = parse_url('https://'.$normalized, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * Reduce a URL to "host/path" with the scheme, the "www." prefix, any
     * query or fragment, and a trailing slash removed, so that cosmetic
     * differences never cost a student a working link. Relative URLs are
     * resolved against the site itself. Returns null when there is nothing
     * host-shaped to judge (a mailto:, an anchor, a bare word).
     */
    private function normalize(string $url): ?string
    {
        $url = trim($url);

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $url = rtrim((string) config('app.url'), '/').$url;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        if (isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $path = rtrim($parts['path'] ?? '', '/');

        return $host.$path;
    }

    /**
     * Every URL the assistant may legitimately hand a student: one per
     * AI-visible page, plus each document's citable reference URL.
     *
     * @return array<string, true>
     */
    private function allowedUrls(): array
    {
        if ($this->allowed !== null) {
            return $this->allowed;
        }

        $baseUrl = rtrim((string) config('app.url'), '/');
        $allowed = [$this->normalize($baseUrl) => true];

        foreach (Page::query()->visibleToAi()->pluck('slug') as $slug) {
            $url = $this->normalize($baseUrl.'/'.ltrim((string) $slug, '/'));

            if ($url !== null) {
                $allowed[$url] = true;
            }
        }

        foreach (CorpusDocument::query()->get() as $document) {
            $url = $this->normalize($document->referenceUrl());

            if ($url !== null) {
                $allowed[$url] = true;
            }
        }

        unset($allowed[null]);

        return $this->allowed = $allowed;
    }

    /**
     * The hosts this assistant speaks for — the site, plus wherever documents
     * are actually hosted (the majors write-ups live on their own domain).
     *
     * @return array<string, true>
     */
    private function hosts(): array
    {
        if ($this->ownedHosts !== null) {
            return $this->ownedHosts;
        }

        $hosts = [];

        foreach (array_keys($this->allowedUrls()) as $url) {
            if (($host = $this->hostOf((string) $url)) !== '') {
                $hosts[$host] = true;
            }
        }

        return $this->ownedHosts = $hosts;
    }
}
