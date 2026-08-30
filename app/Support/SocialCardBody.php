<?php

namespace App\Support;

/**
 * A page's own content, transformed into markup the Takumi engine can lay out.
 *
 * The cards used to be browser screenshots of the page, so they carried the
 * page itself; the designed cards that replaced them carried only a title and a
 * description, which is less than the site was sharing before. This is the body
 * that puts the content back — the real headings, lists, tables, code and
 * images, redrawn rather than photographed.
 *
 * `truncated` is the part the templates care about: it is what earns the quiet
 * «تابع القراءة في الموقع» line at the end. It is decided by
 * {@see SocialCardContent}'s character and image budgets rather than by the
 * layout, so a card knows it cut something before a single pixel is drawn.
 */
final readonly class SocialCardBody
{
    public function __construct(
        public string $html,
        public bool $truncated,
    ) {}

    /** The body a page with no content at all produces. */
    public static function empty(): self
    {
        return new self('', false);
    }

    public function isEmpty(): bool
    {
        return trim($this->html) === '';
    }
}
