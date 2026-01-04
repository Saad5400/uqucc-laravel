<?php

namespace App\Services;

use App\Models\Page;
use DOMDocument;
use DOMNode;
use Illuminate\Support\Facades\Cache;

class TipTapContentExtractor
{
    /**
     * Get extracted content for a page with caching.
     *
     * @return array{message: string|null, buttons: array, attachments: array}
     */
    public function getExtractedContent(Page $page): array
    {
        $cacheKey = $this->getCacheKey($page);

        return Cache::remember(
            $cacheKey,
            config('app-cache.quick_responses.ttl', 3600),
            fn () => $this->extractFromContent($page)
        );
    }

    /**
     * Get the cache key for extracted content.
     */
    protected function getCacheKey(Page $page): string
    {
        $version = $page->updated_at ? $page->updated_at->timestamp : '0';

        return "quick_response_extracted:{$page->id}:{$version}";
    }

    /**
     * Extract message, buttons, and attachments from TipTap JSON content.
     *
     * @return array{message: string|null, buttons: array, attachments: array}
     */
    protected function extractFromContent(Page $page): array
    {
        $content = $page->html_content;

        // If content is a string (legacy HTML), return empty extraction
        if (! is_array($content)) {
            return [
                'message' => null,
                'buttons' => [],
                'attachments' => [],
            ];
        }

        $textParts = [];
        $links = [];
        $attachments = [];

        // Traverse the TipTap JSON structure
        $this->traverseNodes($content['content'] ?? [], $textParts, $links, $attachments);

        // Build the message from text parts
        $message = $this->buildMessage($textParts);

        // Convert links to button format
        $buttons = $this->buildButtons($links);

        return [
            'message' => $message,
            'buttons' => $buttons,
            'attachments' => $attachments,
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
                    if (! empty($node['content'])) {
                        // Pass inList=true for list items
                        $this->traverseNodes($node['content'], $textParts, $links, $attachments, $marks, true);
                    }
                    // Add extra newline after the list ends
                    $textParts[] = "\n";
                    break;

                case 'listItem':
                    $textParts[] = '• ';
                    if (! empty($node['content'])) {
                        $this->traverseNodes($node['content'], $textParts, $links, $attachments, $marks, true);
                    }
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
                    $textParts[] = '> ';
                    if (! empty($node['content'])) {
                        $this->traverseNodes($node['content'], $textParts, $links, $attachments, $marks, $inList);
                    }
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
                    $textParts[] = "\n---\n";
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
        $htmlContent = $node['attrs']['data']['content'] ?? $node['attrs']['content'] ?? null;

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
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        if (! $loaded) {
            return;
        }

        $body = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;

        if (! $body) {
            return;
        }

        $this->traverseDomNodes($body->childNodes, $textParts, $links, $attachments, $inList);
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
                $content = trim($node->nodeValue ?? '');

                if ($content !== '') {
                    $textParts[] = htmlspecialchars($content, ENT_NOQUOTES, 'UTF-8');
                }

                continue;
            }

            if ($node->nodeType !== XML_ELEMENT_NODE) {
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
                    }

                    $this->traverseDomNodes($node->childNodes, $textParts, $links, $attachments, $inList);
                    break;

                case 'br':
                    $textParts[] = "\n";
                    break;

                case 'ul':
                case 'ol':
                    $this->traverseDomNodes($node->childNodes, $textParts, $links, $attachments, true);
                    $textParts[] = "\n";
                    break;

                case 'li':
                    $textParts[] = '• ';
                    $this->traverseDomNodes($node->childNodes, $textParts, $links, $attachments, true);
                    $textParts[] = "\n";
                    break;

                case 'p':
                case 'div':
                case 'section':
                    $this->traverseDomNodes($node->childNodes, $textParts, $links, $attachments, $inList);
                    $textParts[] = $inList ? "\n" : "\n\n";
                    break;

                case 'img':
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
}
