<?php

namespace App\Support;

use App\Helpers\Bidi;
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
     * The tags that lay out as part of a line of text rather than as a box of
     * their own — the ones whose `dir` the image engine drops on the floor.
     * {@see withDirectionMarks()}
     */
    private const INLINE_TAGS = ['span', 'code', 'strong', 'b', 'em', 'i'];

    /** The isolate that opens a run, by the `dir` value that asked for it. */
    private const DIRECTION_ISOLATES = [
        'ltr' => Bidi::LRI,
        'rtl' => Bidi::RLI,
        'auto' => Bidi::FSI,
    ];

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

        $root = self::parse($html);

        if ($root === null) {
            return '<p dir="rtl">'.htmlspecialchars($html, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>';
        }

        self::clean($root);

        return self::serialize($root);
    }

    /**
     * The fragment with every inline run's direction restated as a Unicode
     * isolate — what the question card is rendered from.
     *
     * The card is laid out by the Takumi engine ({@see TakumiRenderer}), which
     * reads `dir` on a block element and ignores it on an inline one. So
     * `<span dir="ltr">(255, 0, 0)</span>` inside an Arabic paragraph came out
     * reordered — «(0, 0, 255)», a different colour and a different answer —
     * while the very same markup read correctly in the admin preview beside
     * it, because a browser honours the attribute. Anything an author fences
     * that way is exactly the content that cannot survive being reordered:
     * coordinates, signed numbers, expressions, calls.
     *
     * The isolates say what the attribute says, in characters the engine's own
     * bidi pass cannot ignore. They are zero-width, so the text they fence is
     * unchanged; this is a rendering step, never a write path.
     *
     * Block-level `dir` is deliberately left alone. The engine honours it, and
     * a `<pre>` opens a fresh bidi paragraph on every line, which an isolate
     * placed at the top of the block would not reach.
     *
     * Inline `<code>` that carries no `dir` is fenced first-strong instead:
     * that is what the template's `unicode-bidi: plaintext` asks for and the
     * engine likewise ignores, and it lets a snippet pick its own direction
     * rather than inherit the Arabic around it.
     */
    public static function withDirectionMarks(string $html): string
    {
        $root = self::parse(trim($html));

        if ($root === null) {
            return $html;
        }

        self::markDirection($root);

        return self::serialize($root);
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
     * The fragment parsed into a throwaway root element, or null when it holds
     * no markup at all. Every pass over the content starts here, so they all
     * see the same document — one parser, one set of quirks.
     */
    private static function parse(string $html): ?DOMElement
    {
        if ($html === '') {
            return null;
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

        return $root instanceof DOMElement ? $root : null;
    }

    /** The root's children back as an HTML fragment, without the root itself. */
    private static function serialize(DOMElement $root): string
    {
        $output = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $root->ownerDocument?->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * Depth-first: fence every inline element that declares a direction — and
     * every inline `<code>` that does not — between the isolate it asks for
     * and a POP DIRECTIONAL ISOLATE. {@see withDirectionMarks()}
     *
     * Inside a `<pre>` nothing is fenced: the block carries its own direction
     * and each of its lines is a bidi paragraph of its own.
     */
    private static function markDirection(DOMNode $node, bool $inPre = false): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            self::markDirection($child, $inPre || $tag === 'pre');

            if ($inPre || ! in_array($tag, self::INLINE_TAGS, true) || ! $child->hasChildNodes()) {
                continue;
            }

            $direction = strtolower($child->getAttribute('dir'));
            $isolate = self::DIRECTION_ISOLATES[$direction]
                ?? ($tag === 'code' ? Bidi::FSI : null);

            if ($isolate === null) {
                continue;
            }

            $document = $child->ownerDocument;

            if ($document === null) {
                continue;
            }

            $child->insertBefore($document->createTextNode($isolate), $child->firstChild);
            $child->appendChild($document->createTextNode(Bidi::PDI));
        }
    }

    /**
     * Depth-first: strip disallowed attributes in place, and unwrap any tag not
     * on the allow-list into its own children so its text survives while the
     * element does not.
     */
    private static function clean(DOMNode $node): void
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

            self::clean($child);

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
