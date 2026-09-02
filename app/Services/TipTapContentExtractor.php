<?php

namespace App\Services;

use App\Models\Page;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Cache;

/**
 * Rewrites a page's content into the HTML Telegram can render.
 *
 * Telegram knows a handful of inline tags — bold, italic, underline, strike,
 * code, pre, links and one level of blockquote — and nothing of block layout.
 * So every block the editor produces is flattened into lines: headings become
 * bold lines, lists get their markers written in, a table becomes one line per
 * row with its cells separated by a bar, and images leave the text to be sent
 * as attachments beside it.
 *
 * Both shapes a page can be stored in are read: the TipTap document new pages
 * carry, and the HTML string older pages still are.
 */
class TipTapContentExtractor
{
    /** How a table draws the boundary between two cells. */
    private const CELL_SEPARATOR = ' | ';

    /** The line a horizontal rule becomes. */
    private const RULE = '———';

    /**
     * Whether traversal is currently inside a blockquote (Telegram forbids nested quotes).
     */
    protected bool $insideBlockquote = false;

    /** Images met during the build in flight, whether or not they became attachments. */
    protected int $images = 0;

    /**
     * Get extracted content for a page with caching.
     *
     * @return array{message: string|null, buttons: array, attachments: array, images: int}
     */
    public function getExtractedContent(Page $page): array
    {
        $cacheKey = $this->getCacheKey($page);

        $extracted = Cache::remember(
            $cacheKey,
            config('app-cache.quick_responses.ttl', 3600),
            fn () => $this->extractFromContent($page)
        );

        $extracted['images'] ??= count($extracted['attachments']);

        return $extracted;
    }

    /**
     * Get the cache key for extracted content.
     *
     * The version segment is bumped when the extraction itself changes shape,
     * so a deploy is not served an hour of the previous rendering.
     */
    protected function getCacheKey(Page $page): string
    {
        $version = $page->updated_at ? $page->updated_at->timestamp : '0';

        return "quick_response_extracted:v2:{$page->id}:{$version}";
    }

    /**
     * Extract message, buttons, and attachments from the page's content.
     *
     * @return array{message: string|null, buttons: array, attachments: array, images: int}
     */
    protected function extractFromContent(Page $page): array
    {
        $content = $page->html_content;

        $textParts = [];
        $links = [];
        $attachments = [];
        $this->images = 0;
        $this->insideBlockquote = false;

        if (is_string($content)) {
            if (trim($content) !== '') {
                $this->extractFromHtmlString($content, $textParts, $links, $attachments);
            }
        } elseif (is_array($content)) {
            $this->traverseNodes($content['content'] ?? [], $textParts, $links, $attachments);
        }

        return [
            'message' => $this->buildMessage($textParts),
            'buttons' => $this->buildButtons($links),
            'attachments' => $attachments,
            'images' => $this->images,
        ];
    }

    /**
     * Recursively traverse TipTap JSON nodes to extract content.
     *
     * @param  bool  $inList  Whether we're currently inside a list (for compact spacing)
     */
    protected function traverseNodes(array $nodes, array &$textParts, array &$links, array &$attachments, array $marks = [], bool $inList = false): void
    {
        foreach ($nodes as $node) {
            $type = $node['type'] ?? null;

            switch ($type) {
                case 'text':
                    $textParts[] = $this->formatText($node['text'] ?? '', $node['marks'] ?? $marks, $links);
                    break;

                case 'paragraph':
                    if (! empty($node['content'])) {
                        $this->traverseNodes($node['content'], $textParts, $links, $attachments, $marks, $inList);
                    }
                    // Use single newline inside lists, double newline outside
                    $textParts[] = $inList ? "\n" : "\n\n";
                    break;

                case 'heading':
                    if (! empty($node['content'])) {
                        $textParts[] = '<b>';
                        $this->traverseNodes($node['content'], $textParts, $links, $attachments, $marks, $inList);
                        $textParts[] = '</b>';
                    }
                    $textParts[] = "\n\n";
                    break;

                case 'bulletList':
                case 'orderedList':
                    $start = (int) ($node['attrs']['start'] ?? 1);

                    foreach (array_values($node['content'] ?? []) as $index => $item) {
                        $textParts[] = $type === 'orderedList' ? ($start + $index).'. ' : '• ';
                        $this->traverseNodes($item['content'] ?? [], $textParts, $links, $attachments, $marks, true);
                    }

                    // Add extra newline after the list ends
                    $textParts[] = "\n";
                    break;

                case 'listItem':
                    // Only reached for an item outside a list node
                    $textParts[] = '• ';
                    if (! empty($node['content'])) {
                        $this->traverseNodes($node['content'], $textParts, $links, $attachments, $marks, true);
                    }
                    break;

                case 'table':
                    $this->handleTableNode($node, $textParts, $links, $attachments, $marks);
                    break;

                case 'link':
                    // Extract link info
                    $href = $node['attrs']['href'] ?? null;
                    $linkText = $this->extractTextFromNodes($node['content'] ?? []);
                    if ($href && $linkText) {
                        $links[] = [
                            'text' => $linkText,
                            'url' => $href,
                        ];
                    }
                    // Also include in text
                    if (! empty($node['content'])) {
                        $this->traverseNodes($node['content'], $textParts, $links, $attachments, $marks, $inList);
                    }
                    break;

                case 'image':
                    $this->images++;
                    $src = $node['attrs']['src'] ?? null;
                    if ($src) {
                        $attachments[] = $this->normalizeAttachmentPath($src);
                    }
                    break;

                case 'file':
                case 'attachment':
                    // Handle file attachment nodes (if used)
                    $src = $node['attrs']['src'] ?? $node['attrs']['href'] ?? null;
                    if ($src) {
                        $attachments[] = $this->normalizeAttachmentPath($src);
                    }
                    break;

                case 'blockquote':
                    if ($this->insideBlockquote) {
                        if (! empty($node['content'])) {
                            $this->traverseNodes($node['content'], $textParts, $links, $attachments, $marks, $inList);
                        }
                        break;
                    }

                    $this->insideBlockquote = true;
                    $quoteParts = [];
                    if (! empty($node['content'])) {
                        $this->traverseNodes($node['content'], $quoteParts, $links, $attachments, $marks, $inList);
                    }
                    $this->insideBlockquote = false;

                    $this->appendQuote($quoteParts, $textParts);
                    break;

                case 'codeBlock':
                    $textParts[] = '<pre>';
                    if (! empty($node['content'])) {
                        $this->traverseNodes($node['content'], $textParts, $links, $attachments, $marks, $inList);
                    }
                    $textParts[] = '</pre>';
                    $textParts[] = "\n\n";
                    break;

                case 'hardBreak':
                    $textParts[] = "\n";
                    break;

                case 'horizontalRule':
                    $textParts[] = "\n".self::RULE."\n\n";
                    break;

                case 'customBlock':
                    $this->handleCustomBlock($node, $textParts, $links, $attachments, $marks, $inList);
                    break;

                case 'alert':
                    $this->handleAlertNode($node, $textParts, $links, $attachments, $marks, $inList);
                    break;

                default:
                    // For any other node type, try to traverse its content
                    if (! empty($node['content'])) {
                        $this->traverseNodes($node['content'], $textParts, $links, $attachments, $marks, $inList);
                    }
                    break;
            }
        }
    }

    /**
     * A table as lines: one per row, cells separated by a bar, header cells bold.
     */
    protected function handleTableNode(array $node, array &$textParts, array &$links, array &$attachments, array $marks): void
    {
        foreach ($node['content'] ?? [] as $row) {
            $cells = [];

            foreach ($row['content'] ?? [] as $cell) {
                $cellParts = [];
                $this->traverseNodes($cell['content'] ?? [], $cellParts, $links, $attachments, $marks, true);

                $text = trim(preg_replace('/\s+/u', ' ', implode('', $cellParts)) ?? '');
                $cells[] = ($cell['type'] ?? null) === 'tableHeader' && $text !== '' ? '<b>'.$text.'</b>' : $text;
            }

            if (array_filter($cells, fn (string $cell): bool => $cell !== '') !== []) {
                $textParts[] = implode(self::CELL_SEPARATOR, $cells)."\n";
            }
        }

        $textParts[] = "\n";
    }

    /**
     * The editor's custom blocks, whose rich content is HTML inside `attrs.config`
     * (a frozen contract, see docs/code-principles.md): an alert is its content,
     * a collapsible is its question in bold over its answer.
     */
    protected function handleCustomBlock(array $node, array &$textParts, array &$links, array &$attachments, array $marks, bool $inList): void
    {
        $config = $node['attrs']['config'] ?? [];

        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }

        if (($node['attrs']['id'] ?? null) === 'collapsible') {
            $question = trim((string) ($config['question'] ?? $node['attrs']['label'] ?? ''));

            if ($question !== '') {
                $textParts[] = '<b>'.htmlspecialchars($question, ENT_NOQUOTES, 'UTF-8').'</b>'."\n";
            }

            $answer = $config['answer'] ?? null;

            if (is_string($answer) && trim($answer) !== '') {
                $this->extractFromHtmlString($answer, $textParts, $links, $attachments, $inList);
            }

            return;
        }

        $html = $config['content'] ?? $config['text'] ?? null;

        if (is_string($html) && trim($html) !== '') {
            $this->extractFromHtmlString($html, $textParts, $links, $attachments, $inList);
        }

        if (! empty($node['content'])) {
            $this->traverseNodes($node['content'], $textParts, $links, $attachments, $marks, $inList);
        }
    }

    /**
     * Wrap collected quote parts as one expandable quote, or nothing when empty.
     */
    protected function appendQuote(array $quoteParts, array &$textParts): void
    {
        $quoteText = trim(implode('', $quoteParts));

        if ($quoteText !== '') {
            $textParts[] = '<blockquote expandable>'.$quoteText.'</blockquote>';
            $textParts[] = "\n\n";
        }
    }

    /**
     * Format text with HTML marks (bold, italic, etc.).
     * Also extracts links from marks and adds them to the links array.
     */
    protected function formatText(string $text, array $marks, array &$links = []): string
    {
        if (empty($marks) || empty($text)) {
            return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
        }

        $formatted = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

        foreach ($marks as $mark) {
            $markType = $mark['type'] ?? null;

            switch ($markType) {
                case 'bold':
                    $formatted = '<b>'.$formatted.'</b>';
                    break;
                case 'italic':
                    $formatted = '<i>'.$formatted.'</i>';
                    break;
                case 'strike':
                    $formatted = '<s>'.$formatted.'</s>';
                    break;
                case 'underline':
                    $formatted = '<u>'.$formatted.'</u>';
                    break;
                case 'code':
                    $formatted = '<code>'.$formatted.'</code>';
                    break;
                case 'link':
                    $href = $mark['attrs']['href'] ?? '';
                    if ($href) {
                        $escapedHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
                        $formatted = '<a href="'.$escapedHref.'">'.$formatted.'</a>';
                        // Also add to links array for button extraction
                        $links[] = [
                            'text' => $text,
                            'url' => $href,
                        ];
                    }
                    break;
            }
        }

        return $formatted;
    }

    /**
     * Extract plain text from nodes (used for link text extraction).
     */
    protected function extractTextFromNodes(array $nodes): string
    {
        $text = '';

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'text') {
                $text .= $node['text'] ?? '';
            } elseif (! empty($node['content'])) {
                $text .= $this->extractTextFromNodes($node['content']);
            }
        }

        return $text;
    }

    /**
     * Normalize attachment path.
     *
     * For internal URLs (matching app.url), returns storage-relative path.
     * For external URLs, returns the full URL as-is.
     */
    protected function normalizeAttachmentPath(string $src): string
    {
        // Check if this is an external URL
        $appUrl = rtrim(config('app.url'), '/');
        $parsedSrc = parse_url($src);

        // If the URL has a host and it doesn't match our app URL, it's external
        if (isset($parsedSrc['host'])) {
            $parsedAppUrl = parse_url($appUrl);
            $appHost = $parsedAppUrl['host'] ?? '';

            // If hosts don't match, keep the full external URL
            if ($parsedSrc['host'] !== $appHost) {
                return $src; // Return full external URL
            }
        }

        // Internal URL - extract the path
        $path = $parsedSrc['path'] ?? $src;

        // Remove /storage/ prefix if present
        if (str_starts_with($path, '/storage/')) {
            $path = substr($path, 9); // Length of '/storage/'
        }

        // Remove leading slash
        return ltrim($path, '/');
    }

    /**
     * Build the final message from text parts.
     */
    protected function buildMessage(array $textParts): ?string
    {
        $message = implode('', $textParts);

        // Each line stands on its own: no stray spaces at either end of it
        $message = preg_replace('/^[ \t]+|[ \t]+$/mu', '', $message);

        // Clean up excessive whitespace
        $message = preg_replace('/\n{3,}/', "\n\n", $message);
        $message = trim($message);

        if (empty($message)) {
            return null;
        }

        // Return HTML formatted message
        return $message;
    }

    /**
     * Build buttons array from extracted links.
     */
    protected function buildButtons(array $links): array
    {
        // Filter and deduplicate links
        $buttons = [];
        $seenUrls = [];

        foreach ($links as $link) {
            $url = $link['url'] ?? '';
            $text = $link['text'] ?? '';

            // Skip empty or duplicate URLs
            if (empty($url) || empty($text) || isset($seenUrls[$url])) {
                continue;
            }

            // Skip internal anchor links
            if (str_starts_with($url, '#')) {
                continue;
            }

            $seenUrls[$url] = true;
            $buttons[] = [
                'text' => $text,
                'url' => $url,
                'size' => 'full', // Default to full width
            ];
        }

        return $buttons;
    }

    /**
     * Handle Alert block nodes which store their rich content as HTML in attrs.
     */
    protected function handleAlertNode(array $node, array &$textParts, array &$links, array &$attachments, array $marks, bool $inList): void
    {
        $htmlContent = $node['attrs']['data']['content']
            ?? $node['attrs']['state']['content']
            ?? $node['attrs']['content']
            ?? null;

        if (is_string($htmlContent) && trim($htmlContent) !== '') {
            $this->extractFromHtmlString($htmlContent, $textParts, $links, $attachments, $inList);
        }

        if (! empty($node['content'])) {
            $this->traverseNodes($node['content'], $textParts, $links, $attachments, $marks, $inList);
        }
    }

    /**
     * Extract text, links, and attachments from an HTML fragment.
     */
    protected function extractFromHtmlString(string $html, array &$textParts, array &$links, array &$attachments, bool $inList = false): void
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        // Wrapped in one element on purpose: without an implied <body>, libxml
        // keeps only the first top-level element as the document element.
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $loaded ? $dom->documentElement : null;

        if (! $root) {
            return;
        }

        $this->traverseDomNodes($root->childNodes, $textParts, $links, $attachments, $inList);
        $textParts[] = $inList ? "\n" : "\n\n";
    }

    /**
     * Traverse DOM nodes to collect text, links, and attachments.
     *
     * @param  iterable<DOMNode>  $nodes
     */
    protected function traverseDomNodes(iterable $nodes, array &$textParts, array &$links, array &$attachments, bool $inList = false): void
    {
        foreach ($nodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                // Runs of whitespace fold to one space, and the space itself is
                // kept: it is what separates a word from the marked word after
                // it. A stray one at a line's edge is trimmed by buildMessage().
                $content = preg_replace('/\s+/u', ' ', $node->nodeValue ?? '') ?? '';

                if ($content === '' || ($content === ' ' && $textParts === [])) {
                    continue;
                }

                $textParts[] = htmlspecialchars($content, ENT_NOQUOTES, 'UTF-8');

                continue;
            }

            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->nodeName);

            switch ($tag) {
                case 'a':
                    $href = $node->getAttribute('href');
                    $anchorText = trim($node->textContent ?? '') ?: $href;

                    if ($href) {
                        $links[] = [
                            'text' => $anchorText,
                            'url' => $href,
                        ];

                        $escapedHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
                        $textParts[] = '<a href="'.$escapedHref.'">'.htmlspecialchars($anchorText, ENT_NOQUOTES, 'UTF-8').'</a>';
                        break;
                    }

                    $this->traverseDomNodes($node->childNodes, $textParts, $links, $attachments, $inList);
                    break;

                case 'br':
                    $textParts[] = "\n";
                    break;

                case 'hr':
                    $textParts[] = "\n".self::RULE."\n\n";
                    break;

                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                    $textParts[] = '<b>';
                    $this->traverseDomNodes($node->childNodes, $textParts, $links, $attachments, $inList);
                    $textParts[] = '</b>';
                    $textParts[] = "\n\n";
                    break;

                case 'strong':
                case 'b':
                    $this->wrapDomChildren($node, 'b', $textParts, $links, $attachments, $inList);
                    break;

                case 'em':
                case 'i':
                    $this->wrapDomChildren($node, 'i', $textParts, $links, $attachments, $inList);
                    break;

                case 'u':
                    $this->wrapDomChildren($node, 'u', $textParts, $links, $attachments, $inList);
                    break;

                case 's':
                case 'del':
                case 'strike':
                    $this->wrapDomChildren($node, 's', $textParts, $links, $attachments, $inList);
                    break;

                case 'code':
                    $this->wrapDomChildren($node, 'code', $textParts, $links, $attachments, $inList);
                    break;

                case 'pre':
                    $textParts[] = '<pre>'.htmlspecialchars(trim($node->textContent ?? ''), ENT_NOQUOTES, 'UTF-8').'</pre>';
                    $textParts[] = "\n\n";
                    break;

                case 'blockquote':
                    if ($this->insideBlockquote) {
                        $this->traverseDomNodes($node->childNodes, $textParts, $links, $attachments, $inList);
                        break;
                    }

                    $this->insideBlockquote = true;
                    $quoteParts = [];
                    $this->traverseDomNodes($node->childNodes, $quoteParts, $links, $attachments, $inList);
                    $this->insideBlockquote = false;

                    $this->appendQuote($quoteParts, $textParts);
                    break;

                case 'ul':
                case 'ol':
                    $start = max(1, (int) ($node->getAttribute('start') ?: 1));
                    $index = 0;

                    foreach ($node->childNodes as $item) {
                        if (! $item instanceof DOMElement || strtolower($item->nodeName) !== 'li') {
                            continue;
                        }

                        $textParts[] = $tag === 'ol' ? ($start + $index).'. ' : '• ';
                        $this->traverseDomNodes($item->childNodes, $textParts, $links, $attachments, true);
                        $textParts[] = "\n";
                        $index++;
                    }

                    $textParts[] = "\n";
                    break;

                case 'li':
                    // Only reached for an item outside a list
                    $textParts[] = '• ';
                    $this->traverseDomNodes($node->childNodes, $textParts, $links, $attachments, true);
                    $textParts[] = "\n";
                    break;

                case 'table':
                    $this->handleDomTable($node, $textParts, $links, $attachments);
                    break;

                case 'p':
                case 'div':
                case 'section':
                    $this->traverseDomNodes($node->childNodes, $textParts, $links, $attachments, $inList);
                    $textParts[] = $inList ? "\n" : "\n\n";
                    break;

                case 'img':
                    $this->images++;
                    $src = $node->getAttribute('src');

                    if ($src) {
                        $attachments[] = $this->normalizeAttachmentPath($src);
                    }
                    break;

                default:
                    $this->traverseDomNodes($node->childNodes, $textParts, $links, $attachments, $inList);
                    break;
            }
        }
    }

    /**
     * An inline element's children inside one Telegram tag.
     */
    protected function wrapDomChildren(DOMElement $node, string $tag, array &$textParts, array &$links, array &$attachments, bool $inList): void
    {
        $textParts[] = "<{$tag}>";
        $this->traverseDomNodes($node->childNodes, $textParts, $links, $attachments, $inList);
        $textParts[] = "</{$tag}>";
    }

    /**
     * A legacy HTML table as lines, the way {@see handleTableNode} draws a TipTap one.
     */
    protected function handleDomTable(DOMElement $table, array &$textParts, array &$links, array &$attachments): void
    {
        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = [];

            foreach ($row->childNodes as $cell) {
                if (! $cell instanceof DOMElement || ! in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    continue;
                }

                $cellParts = [];
                $this->traverseDomNodes($cell->childNodes, $cellParts, $links, $attachments, true);

                $text = trim(preg_replace('/\s+/u', ' ', implode('', $cellParts)) ?? '');
                $cells[] = strtolower($cell->nodeName) === 'th' && $text !== '' ? '<b>'.$text.'</b>' : $text;
            }

            if (array_filter($cells, fn (string $cell): bool => $cell !== '') !== []) {
                $textParts[] = implode(self::CELL_SEPARATOR, $cells)."\n";
            }
        }

        $textParts[] = "\n";
    }
}
