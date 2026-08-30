<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Rewrites a page's content into markup the Takumi engine can lay out.
 *
 * The page is authored in TipTap and stored as its JSON document (older pages
 * are a raw HTML string). Neither can be handed to the engine as-is: it lays
 * out flexbox, not the CSS box model, and it has opinions the browser does not
 * — an inline element gets no box, `<table>` is not a layout, `list-style`
 * draws nothing, an `<img>` needs its bytes inline and its size in advance, and
 * a run of text loses its own weight when its parent is not at 400.
 *
 * So this is a WHITELIST, not a filter. Nothing is passed through; every node
 * this understands is re-emitted as the small vocabulary of flex boxes the card
 * templates style (`c-*` classes, shared by both cards through
 * resources/views/social/content-style.blade.php), and every node it does not
 * understand contributes its text and nothing else. A tag that arrives here for
 * the first time can therefore be ugly, but it cannot break the render.
 *
 * What it costs to draw is budgeted in characters and images, not in pixels:
 * the caller says how much room its card has, the walk stops when that runs
 * out, and {@see SocialCardBody::$truncated} tells the template to say so. The
 * templates additionally cap the content box in CSS — belt and braces for the
 * one thing a character count cannot predict, an image's height.
 *
 * The instance carries the budget of the build in flight, so a build is not
 * re-entrant; it is resolved per render and never shared across one.
 */
class SocialCardContent
{
    /** Rows of a table drawn before the rest is summarised away. */
    private const MAX_TABLE_ROWS = 8;

    /** Columns drawn before the rest of a row is dropped. */
    private const MAX_TABLE_COLUMNS = 5;

    /**
     * What one image costs against the character budget.
     *
     * Sized in the currency the budget is kept in: an image is drawn up to 380
     * pixels tall, which is about ten lines of body text, which is about this
     * many characters. It matters that this is not an underestimate — the
     * budget is what makes the card say «تابع القراءة في الموقع» when it stops
     * short, and the templates' `max-height` is only a backstop, which cannot
     * say anything.
     */
    private const IMAGE_COST = 480;

    /**
     * How deep the walk will follow a document before it stops.
     *
     * Page content is authored, not hostile, but it is also not validated for
     * shape: a list inside a quote inside a table cell inside a custom block is
     * ordinary, and a document that nests a thousand deep — a bad import, a
     * paste from somewhere strange — would take the stack with it. Twelve is
     * far past anything the editor produces.
     */
    private const MAX_DEPTH = 12;

    /** Tags whose contents are dropped entirely rather than unwrapped. */
    private const DROPPED_TAGS = ['script', 'style', 'template', 'noscript', 'svg'];

    /** Tags that stand for something a card cannot draw, and get a labelled chip instead. */
    private const EMBED_TAGS = ['iframe', 'video', 'audio', 'object', 'embed', 'canvas'];

    private int $charactersLeft = 0;

    private int $imagesLeft = 0;

    private int $contentWidth = 0;

    private bool $truncated = false;

    private int $depth = 0;

    public function __construct(private readonly SocialCardImages $images = new SocialCardImages) {}

    /**
     * The page's content as card markup.
     *
     * @param  array<string, mixed>|string|null  $content  Page::$html_content — a TipTap document or legacy HTML.
     * @param  int  $characterBudget  Readable characters the card has room for.
     * @param  int  $imageBudget  Images it has room for; zero keeps the build off the network entirely.
     * @param  int  $contentWidth  The content column's width in CSS pixels, which is what an image is scaled to.
     */
    public function build(array|string|null $content, int $characterBudget, int $imageBudget, int $contentWidth): SocialCardBody
    {
        $this->charactersLeft = $characterBudget;
        $this->imagesLeft = $imageBudget;
        $this->contentWidth = $contentWidth;
        $this->truncated = false;
        $this->depth = 0;
        $this->images->reset();

        $html = match (true) {
            is_array($content) => $this->fromDocument($content),
            is_string($content) => $this->fromHtml($content),
            default => '',
        };

        return new SocialCardBody(trim($html), $this->truncated && trim($html) !== '');
    }

    /*
    |--------------------------------------------------------------------------
    | The TipTap document
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $document
     */
    private function fromDocument(array $document): string
    {
        return $this->nodes(is_array($document['content'] ?? null) ? $document['content'] : []);
    }

    /**
     * @param  list<mixed>  $nodes
     */
    private function nodes(array $nodes): string
    {
        if (! $this->descend()) {
            return '';
        }

        $html = '';

        foreach ($nodes as $node) {
            if ($this->spent()) {
                break;
            }

            if (is_array($node)) {
                $html .= $this->node($node);
            }
        }

        $this->ascend();

        return $html;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function node(array $node): string
    {
        $children = is_array($node['content'] ?? null) ? $node['content'] : [];

        return match ($node['type'] ?? null) {
            'heading' => $this->heading((int) ($node['attrs']['level'] ?? 2), $this->inline($children)),
            'paragraph' => $this->paragraph($this->inline($children), $node),
            'bulletList' => $this->list($children, ordered: false),
            'orderedList' => $this->list($children, ordered: true),
            'blockquote' => $this->wrap('c-quote', $this->nodes($children)),
            'codeBlock' => $this->code($this->plainText($children)),
            'horizontalRule' => '<div class="c-hr"></div>',
            'image' => $this->image((string) ($node['attrs']['src'] ?? ''), (string) ($node['attrs']['alt'] ?? '')),
            'table' => $this->table($children),
            'customBlock', 'alertBlock', 'collapsibleBlock' => $this->customBlock($node),

            // Anything unknown still contributes what it says. Recursing rather
            // than dropping is what keeps a new editor extension from silently
            // emptying every card on the site.
            default => $children === [] ? '' : $this->nodes($children),
        };
    }

    /**
     * A paragraph, plus any images that were sitting inside it.
     *
     * The editor configures images as INLINE nodes, so they arrive nested in a
     * paragraph's children. An inline element gets no box from the engine, so
     * they are hoisted out and drawn as their own block, in reading order.
     *
     * @param  array<string, mixed>  $node
     */
    private function paragraph(string $inline, array $node): string
    {
        $images = '';

        foreach ($this->imageNodesIn(is_array($node['content'] ?? null) ? $node['content'] : []) as $image) {
            $images .= $this->image($image['src'], $image['alt']);
        }

        $text = trim($inline) === '' ? '' : '<div class="c-p"'.$this->alignment($node).'>'.$inline.'</div>';

        return $text.$images;
    }

    private function heading(int $level, string $inline): string
    {
        if (trim($inline) === '') {
            return '';
        }

        // Two sizes, not six: a card is a few hundred pixels tall and an h5 that
        // is one pixel smaller than an h4 reads as a mistake rather than a level.
        return '<div class="'.($level <= 2 ? 'c-h2' : 'c-h3').'">'.$inline.'</div>';
    }

    /**
     * @param  list<mixed>  $items
     */
    private function list(array $items, bool $ordered, bool $nested = false): string
    {
        $html = '';
        $number = 0;

        foreach ($items as $item) {
            if ($this->spent()) {
                break;
            }

            if (! is_array($item)) {
                continue;
            }

            $number++;
            $children = is_array($item['content'] ?? null) ? $item['content'] : [];
            $inline = '';
            $sublists = '';

            foreach ($children as $child) {
                $type = is_array($child) ? ($child['type'] ?? null) : null;

                if ($type === 'bulletList' || $type === 'orderedList') {
                    $sublists .= $this->list(is_array($child['content'] ?? null) ? $child['content'] : [], $type === 'orderedList', nested: true);

                    continue;
                }

                $inline .= ($inline === '' ? '' : ' ').$this->inline(is_array($child['content'] ?? null) ? $child['content'] : [$child]);
            }

            $html .= $this->listRow($this->marker($ordered, $number, $nested), $inline, $nested).$sublists;
        }

        return $html;
    }

    /**
     * The bullet or number a row carries. A nested level gets a hollow bullet,
     * which is the only depth cue a flat row can offer besides its indent.
     */
    private function marker(bool $ordered, int $number, bool $nested): string
    {
        return $ordered ? $number.'.' : ($nested ? '◦' : '•');
    }

    /**
     * One list row: the marker and the text as two flex children, because the
     * engine draws no marker of its own for `list-style`.
     */
    private function listRow(string $marker, string $inline, bool $nested): string
    {
        if (trim($inline) === '') {
            return '';
        }

        return '<div class="c-li'.($nested ? ' c-li-nested' : '').'">'
            .'<span class="c-li-mark">'.$this->escape($marker).'</span>'
            .'<span class="c-li-text">'.$inline.'</span>'
            .'</div>';
    }

    private function code(string $text): string
    {
        $text = $this->spend($text);

        return trim($text) === '' ? '' : '<pre class="c-pre">'.$this->escape($text).'</pre>';
    }

    /**
     * A picture, drawn if it can be fetched and afforded, and named if it cannot.
     */
    private function image(string $src, string $alt): string
    {
        if ($this->imagesLeft < 1) {
            // Not a failure — the card ran out of room for pictures — but the
            // reader should still know one was here.
            return $this->imagePlaceholder($alt);
        }

        $resolved = $this->images->resolve($src, $this->contentWidth);

        if ($resolved === null) {
            return $this->imagePlaceholder($alt);
        }

        $this->imagesLeft--;
        $this->spend(str_repeat(' ', self::IMAGE_COST));

        return '<div class="c-figure"><img src="'.$resolved['uri'].'" width="'.$resolved['width'].'" height="'.$resolved['height'].'" alt=""></div>';
    }

    private function imagePlaceholder(string $alt): string
    {
        $alt = trim($alt);

        return '<div class="c-chip">'.$this->escape($alt === '' ? 'صورة' : 'صورة: '.$alt).'</div>';
    }

    /**
     * A table as rows of flex cells.
     *
     * `<table>` is not a layout the engine performs, and the browser's column
     * sizing is exactly what it does not do — so every cell simply shares the
     * row evenly. That is wrong for a table of one wide column and four narrow
     * ones, and right for the comparison grids these pages actually carry.
     *
     * @param  list<mixed>  $rows
     */
    private function table(array $rows): string
    {
        $html = '';
        $drawn = 0;

        foreach ($rows as $row) {
            if (! is_array($row) || ($row['type'] ?? null) !== 'tableRow') {
                continue;
            }

            if ($drawn >= self::MAX_TABLE_ROWS || $this->spent()) {
                $this->truncated = true;
                $html .= '<div class="c-tr"><div class="c-td c-td-more">…</div></div>';

                break;
            }

            $cells = '';
            $isHeader = false;
            $column = 0;

            foreach (is_array($row['content'] ?? null) ? $row['content'] : [] as $cell) {
                if (! is_array($cell) || $column >= self::MAX_TABLE_COLUMNS) {
                    continue;
                }

                $column++;
                $isHeader = $isHeader || ($cell['type'] ?? null) === 'tableHeader';
                $cells .= '<div class="c-td">'.$this->escape($this->spend($this->plainText(is_array($cell['content'] ?? null) ? $cell['content'] : []))).'</div>';
            }

            if ($cells === '') {
                continue;
            }

            $drawn++;
            $html .= '<div class="c-tr'.($isHeader ? ' c-tr-head' : '').'">'.$cells.'</div>';
        }

        return $html === '' ? '' : '<div class="c-table">'.$html.'</div>';
    }

    /**
     * The editor's two custom blocks. Both store HTML inside `attrs.config`,
     * which may itself be a JSON string — the FROZEN contract the node views
     * read the same way.
     *
     * @param  array<string, mixed>  $node
     */
    private function customBlock(array $node): string
    {
        $config = $node['attrs']['config'] ?? [];

        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($config)) {
            $config = [];
        }

        $id = $node['attrs']['id'] ?? ($node['type'] === 'collapsibleBlock' ? 'collapsible' : 'alert');

        if ($id === 'collapsible') {
            $question = $this->spend(trim((string) ($config['question'] ?? '')));
            $answer = $this->fromHtml((string) ($config['answer'] ?? ''));

            // A card cannot be unfolded, so the fold is simply opened: the
            // question becomes a heading over its own answer.
            return $question === '' && $answer === ''
                ? ''
                : '<div class="c-collapse">'
                    .($question === '' ? '' : '<div class="c-collapse-q">'.$this->escape($question).'</div>')
                    .$answer
                    .'</div>';
        }

        $body = $this->fromHtml((string) ($config['content'] ?? ''));

        return $body === '' ? '' : '<div class="c-alert">'.$body.'</div>';
    }

    /*
    |--------------------------------------------------------------------------
    | Legacy HTML content
    |--------------------------------------------------------------------------
    |
    | Pages written before the editor, and the HTML the custom blocks store
    | inside their own attributes, arrive as a string. They go through the same
    | whitelist and come out as the same vocabulary.
    |
    */

    private function fromHtml(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="card-content-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('card-content-root');

        if (! $root instanceof DOMElement) {
            return $this->paragraphOf($this->spend(trim(strip_tags($html))));
        }

        return $this->htmlChildren($root);
    }

    private function htmlChildren(DOMNode $parent): string
    {
        if (! $this->descend()) {
            return '';
        }

        $html = '';
        $looseInline = '';

        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($this->spent()) {
                break;
            }

            if ($child instanceof DOMText) {
                $looseInline .= $this->escape($this->spend($child->textContent));

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            // Inline tags at block level are collected into a paragraph of
            // their own rather than each becoming a block, so a sentence with
            // a bold word in it stays one sentence.
            if ($this->isInlineTag($tag)) {
                $looseInline .= $this->htmlInline($child);

                continue;
            }

            $html .= $this->paragraphOf($looseInline, escaped: true);
            $looseInline = '';
            $html .= $this->htmlBlock($child, $tag);
        }

        $this->ascend();

        return $html.$this->paragraphOf($looseInline, escaped: true);
    }

    private function htmlBlock(DOMElement $element, string $tag): string
    {
        if (in_array($tag, self::DROPPED_TAGS, true)) {
            return '';
        }

        if (in_array($tag, self::EMBED_TAGS, true)) {
            // The engine has no player and no frame. Saying so is better than a
            // blank space where the reader can see something is missing.
            return '<div class="c-chip">'.$this->escape($tag === 'iframe' ? 'محتوى مضمّن' : 'مقطع مرئي').'</div>';
        }

        return match ($tag) {
            'h1', 'h2' => $this->heading(2, $this->htmlInline($element)),
            'h3', 'h4', 'h5', 'h6' => $this->heading(3, $this->htmlInline($element)),
            'p' => $this->paragraphOf($this->htmlInline($element), escaped: true),
            'ul', 'ol' => $this->htmlList($element, $tag === 'ol'),
            'li' => $this->listRow('•', $this->htmlInline($element), nested: false),
            'blockquote' => $this->wrap('c-quote', $this->htmlChildren($element)),
            'pre' => $this->code($element->textContent),
            'hr' => '<div class="c-hr"></div>',
            'img' => $this->image($element->getAttribute('src'), $element->getAttribute('alt')),
            'figure', 'figcaption', 'div', 'section', 'article', 'main', 'header', 'footer', 'details', 'summary' => $this->htmlChildren($element),
            'table', 'thead', 'tbody', 'tfoot' => $this->htmlTable($element),
            default => $this->htmlChildren($element),
        };
    }

    private function htmlList(DOMElement $list, bool $ordered): string
    {
        $html = '';
        $number = 0;

        foreach ($list->getElementsByTagName('li') as $item) {
            if ($this->spent()) {
                break;
            }

            // Only this list's own items; a nested list's items are drawn by
            // the recursive call below, one indent in.
            if ($item->parentNode !== $list) {
                continue;
            }

            $number++;
            $html .= $this->listRow($ordered ? $number.'.' : '•', $this->htmlInline($item, skipLists: true), nested: false);

            foreach ($item->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), ['ul', 'ol'], true)) {
                    $html .= $this->htmlNestedList($child, strtolower($child->nodeName) === 'ol');
                }
            }
        }

        return $html;
    }

    private function htmlNestedList(DOMElement $list, bool $ordered): string
    {
        $html = '';
        $number = 0;

        foreach ($list->childNodes as $item) {
            if (! $item instanceof DOMElement || strtolower($item->nodeName) !== 'li' || $this->spent()) {
                continue;
            }

            $number++;
            $html .= $this->listRow($ordered ? $number.'.' : '◦', $this->htmlInline($item, skipLists: true), nested: true);
        }

        return $html;
    }

    private function htmlTable(DOMElement $table): string
    {
        $html = '';
        $drawn = 0;

        foreach ($table->getElementsByTagName('tr') as $row) {
            if ($drawn >= self::MAX_TABLE_ROWS || $this->spent()) {
                $this->truncated = true;
                $html .= '<div class="c-tr"><div class="c-td c-td-more">…</div></div>';

                break;
            }

            $cells = '';
            $isHeader = false;
            $column = 0;

            foreach ($row->childNodes as $cell) {
                if (! $cell instanceof DOMElement || $column >= self::MAX_TABLE_COLUMNS) {
                    continue;
                }

                $name = strtolower($cell->nodeName);

                if ($name !== 'td' && $name !== 'th') {
                    continue;
                }

                $column++;
                $isHeader = $isHeader || $name === 'th';
                $cells .= '<div class="c-td">'.$this->escape($this->spend($cell->textContent)).'</div>';
            }

            if ($cells === '') {
                continue;
            }

            $drawn++;
            $html .= '<div class="c-tr'.($isHeader ? ' c-tr-head' : '').'">'.$cells.'</div>';
        }

        return $html === '' ? '' : '<div class="c-table">'.$html.'</div>';
    }

    /**
     * The inline run of an element: text, emphasis, links and breaks.
     */
    private function htmlInline(DOMNode $parent, bool $skipLists = false): string
    {
        if (! $this->descend()) {
            return '';
        }

        $html = '';

        foreach ($parent->childNodes as $child) {
            if ($this->spent()) {
                break;
            }

            if ($child instanceof DOMText) {
                $html .= $this->escape($this->spend($child->textContent));

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if ($skipLists && in_array($tag, ['ul', 'ol'], true)) {
                continue;
            }

            if (in_array($tag, self::DROPPED_TAGS, true)) {
                continue;
            }

            $html .= match ($tag) {
                'br' => '<br>',
                'strong', 'b' => '<strong>'.$this->htmlInline($child).'</strong>',
                'em', 'i' => '<em>'.$this->htmlInline($child).'</em>',
                'u' => '<span class="c-u">'.$this->htmlInline($child).'</span>',
                's', 'strike', 'del' => '<span class="c-s">'.$this->htmlInline($child).'</span>',
                'code' => '<span class="c-code">'.$this->htmlInline($child).'</span>',
                'a' => '<span class="c-link">'.$this->htmlInline($child).'</span>',
                'img' => '',
                default => $this->htmlInline($child),
            };
        }

        $this->ascend();

        return $html;
    }

    private function isInlineTag(string $tag): bool
    {
        return in_array($tag, ['strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'code', 'a', 'span', 'br', 'small', 'mark', 'sub', 'sup'], true);
    }

    /*
    |--------------------------------------------------------------------------
    | TipTap inline runs
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<mixed>  $nodes
     */
    private function inline(array $nodes): string
    {
        if (! $this->descend()) {
            return '';
        }

        $html = '';

        foreach ($nodes as $node) {
            if ($this->spent()) {
                break;
            }

            if (! is_array($node)) {
                continue;
            }

            $html .= match ($node['type'] ?? null) {
                'text' => $this->marked($this->escape($this->spend((string) ($node['text'] ?? ''))), $node['marks'] ?? []),
                'hardBreak' => '<br>',

                // Images are hoisted to their own block by the paragraph that
                // holds them; here they would be an inline box the engine does
                // not draw.
                'image' => '',
                default => $this->inline(is_array($node['content'] ?? null) ? $node['content'] : []),
            };
        }

        $this->ascend();

        return $html;
    }

    /**
     * Wrap a run in its marks.
     *
     * Order matters for exactly one reason: the engine drops a run's own weight
     * when an ancestor sets a non-default one, so `strong` has to be the
     * OUTERMOST wrapper — everything else here changes colour or decoration,
     * neither of which a parent can take away.
     */
    private function marked(string $html, mixed $marks): string
    {
        if ($html === '' || ! is_array($marks)) {
            return $html;
        }

        $types = [];

        foreach ($marks as $mark) {
            if (is_array($mark) && is_string($mark['type'] ?? null)) {
                $types[] = $mark['type'];
            }
        }

        foreach (['code', 'link', 'underline', 'strike', 'italic', 'bold'] as $type) {
            if (! in_array($type, $types, true)) {
                continue;
            }

            $html = match ($type) {
                'code' => '<span class="c-code">'.$html.'</span>',
                'link' => '<span class="c-link">'.$html.'</span>',
                'underline' => '<span class="c-u">'.$html.'</span>',
                'strike' => '<span class="c-s">'.$html.'</span>',
                'italic' => '<em>'.$html.'</em>',
                'bold' => '<strong>'.$html.'</strong>',
            };
        }

        return $html;
    }

    /**
     * Every image node in a subtree, in reading order.
     *
     * @param  list<mixed>  $nodes
     * @return list<array{src: string, alt: string}>
     */
    private function imageNodesIn(array $nodes): array
    {
        $found = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'image') {
                $found[] = [
                    'src' => trim((string) ($node['attrs']['src'] ?? '')),
                    'alt' => trim((string) ($node['attrs']['alt'] ?? '')),
                ];
            }

            if (is_array($node['content'] ?? null)) {
                array_push($found, ...$this->imageNodesIn($node['content']));
            }
        }

        return $found;
    }

    /**
     * The plain text of a subtree — what a table cell and a code block get,
     * neither of which has room for emphasis.
     *
     * @param  list<mixed>  $nodes
     */
    private function plainText(array $nodes): string
    {
        $parts = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'text') {
                $parts[] = (string) ($node['text'] ?? '');

                continue;
            }

            if (($node['type'] ?? null) === 'hardBreak') {
                $parts[] = "\n";

                continue;
            }

            if (is_array($node['content'] ?? null)) {
                $parts[] = $this->plainText($node['content']);
            }
        }

        return trim(implode(' ', array_filter($parts, fn (string $part): bool => $part !== '')));
    }

    /*
    |--------------------------------------------------------------------------
    | Budget and helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Take `$text` out of the character budget, cutting it at a word boundary
     * when it is the piece that empties it.
     */
    private function spend(string $text): string
    {
        if ($this->charactersLeft <= 0) {
            $this->truncated = true;

            return '';
        }

        $length = mb_strlen($text);

        if ($length <= $this->charactersLeft) {
            $this->charactersLeft -= $length;

            return $text;
        }

        $kept = rtrim(mb_substr($text, 0, $this->charactersLeft));
        $lastSpace = mb_strrpos($kept, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            $kept = mb_substr($kept, 0, $lastSpace);
        }

        $this->charactersLeft = 0;
        $this->truncated = true;

        return $kept === '' ? '' : $kept.'…';
    }

    private function spent(): bool
    {
        return $this->charactersLeft <= 0;
    }

    /**
     * Step one level into the document, or refuse to. A refusal is a
     * truncation like any other — something the page says did not reach the
     * card — so it is reported the same way.
     */
    private function descend(): bool
    {
        if ($this->depth >= self::MAX_DEPTH) {
            $this->truncated = true;

            return false;
        }

        $this->depth++;

        return true;
    }

    private function ascend(): void
    {
        $this->depth--;
    }

    private function paragraphOf(string $inline, bool $escaped = false): string
    {
        $inline = $escaped ? $inline : $this->escape($inline);

        return trim(strip_tags($inline)) === '' && ! str_contains($inline, '<img') ? '' : '<div class="c-p">'.$inline.'</div>';
    }

    private function wrap(string $class, string $inner): string
    {
        return trim($inner) === '' ? '' : '<div class="'.$class.'">'.$inner.'</div>';
    }

    /**
     * The `text-align` an author set on a block, as an inline style — the one
     * attribute worth carrying, because a centred line that comes out
     * start-aligned looks like a bug in the card rather than in the page.
     *
     * @param  array<string, mixed>  $node
     */
    private function alignment(array $node): string
    {
        $align = $node['attrs']['textAlign'] ?? null;

        return in_array($align, ['center', 'end', 'right', 'left'], true)
            ? ' style="text-align:'.$align.'"'
            : '';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
