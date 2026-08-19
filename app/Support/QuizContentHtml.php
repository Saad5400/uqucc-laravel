<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * The daily question is authored as an HTML fragment and rendered to an image,
 * so mixed Arabic/code content can carry its own direction instead of fighting
 * Telegram's bidi algorithm. This keeps that fragment to a small, safe
 * vocabulary: the structural and inline tags the image template knows how to
 * lay out, each stripped of every attribute except an explicit `dir`.
 *
 * The same fragment reaches the browser in the admin live preview, so
 * sanitizing here — at every write path — is what keeps that preview from
 * rendering author- or model-supplied script.
 */
class QuizContentHtml
{
    /** Tags the image template and preview style; anything else is unwrapped to its text. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'pre', 'code', 'strong', 'b', 'em', 'i',
        'span', 'ul', 'ol', 'li', 'h3', 'h4', 'div',
    ];

    /** The only attribute kept, and only with one of these directional values. */
    private const ALLOWED_DIR = ['rtl', 'ltr', 'auto'];

    /** Tags removed with their contents, rather than unwrapped, so no script/style text leaks. */
    private const DROPPED_TAGS = ['script', 'style', 'template', 'iframe', 'object', 'embed'];

    /**
     * Return the fragment with every disallowed tag unwrapped, every attribute
     * but a valid `dir` removed, and surrounding whitespace trimmed. An empty
     * or tagless fragment round-trips to its plain text wrapped in one
     * paragraph, so the stored value is always renderable HTML.
     */
    public static function sanitize(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="quiz-content-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementsByTagName('div')->item(0);

        if (! $root instanceof DOMElement) {
            return '<p dir="rtl">'.htmlspecialchars($html, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>';
        }

        self::clean($root, $document);

        $output = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * The length of the human-readable text, ignoring the markup — what the
     * character caps are really about, so an author is never penalised for the
     * weight of the tags.
     */
    public static function textLength(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_strlen(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }

    /**
     * The fragment as plain text, for read-back surfaces (the admin assistant's
     * inspection view, the "do not repeat" recent-questions list) that show the
     * question without rendering it.
     */
    public static function toPlainText(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{2,}/", "\n", $text) ?? '');
    }

    /**
     * Depth-first: strip disallowed attributes in place, and unwrap any tag not
     * on the allow-list into its own children so its text survives while the
     * element does not.
     */
    private static function clean(DOMNode $node, DOMDocument $document): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROPPED_TAGS, true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            self::clean($child, $document);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                self::unwrap($child);

                continue;
            }

            self::stripAttributes($child);
        }
    }

    /**
     * Replace an element with its child nodes, keeping their order.
     */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }

    private static function stripAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $keep = strtolower($attribute->nodeName) === 'dir'
                && in_array(strtolower($attribute->nodeValue ?? ''), self::ALLOWED_DIR, true);

            if (! $keep) {
                $element->removeAttribute($attribute->nodeName);
            }
        }
    }
}
