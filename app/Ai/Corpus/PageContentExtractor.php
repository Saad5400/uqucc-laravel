<?php

namespace App\Ai\Corpus;

use App\Models\Page;

/**
 * Turns a CMS page into the markdown text the corpus ingests.
 *
 * Page::$html_content is either a TipTap JSON document (array, the current
 * editor format) or a legacy markdown/HTML string. Both collapse to markdown
 * whose heading lines drive the heading-aware chunker; the page title is
 * prepended as an H1 so title terms are always searchable and every chunk of
 * a short page inherits it as heading context.
 */
class PageContentExtractor
{
    public function extract(Page $page): string
    {
        return trim('# '.trim($page->title)."\n\n".$this->markdownFromContent($page->html_content));
    }

    /**
     * Markdown for a raw html_content value (TipTap array or legacy string),
     * WITHOUT the title heading extract() prepends. Public because the admin
     * copilot needs the same flattening for editor state that is not saved
     * to a Page yet.
     *
     * @param  array<string, mixed>|string|null  $content
     */
    public function markdownFromContent(array|string|null $content): string
    {
        return is_array($content)
            ? $this->tipTapToMarkdown($content['content'] ?? [])
            : trim((string) $content);
    }

    /**
     * Flatten TipTap nodes to markdown. Only the block structure retrieval
     * cares about is preserved (headings, paragraphs, lists, quotes, code);
     * everything else contributes its plain text.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private function tipTapToMarkdown(array $nodes): string
    {
        $blocks = [];

        foreach ($nodes as $node) {
            $type = $node['type'] ?? null;
            $children = is_array($node['content'] ?? null) ? $node['content'] : [];

            switch ($type) {
                case 'heading':
                    $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 2)));
                    $text = $this->inlineText($children);

                    if ($text !== '') {
                        // Author headings start at H2 — H1 is reserved for the
                        // page title prepended by extract().
                        $blocks[] = str_repeat('#', max(2, $level)).' '.$text;
                    }
                    break;

                case 'paragraph':
                    $text = $this->inlineText($children);

                    if ($text !== '') {
                        $blocks[] = $text;
                    }
                    break;

                case 'bulletList':
                case 'orderedList':
                    $items = [];

                    foreach ($children as $listItem) {
                        $text = $this->inlineText(is_array($listItem['content'] ?? null) ? $listItem['content'] : []);

                        if ($text !== '') {
                            $items[] = '- '.$text;
                        }
                    }

                    if ($items !== []) {
                        $blocks[] = implode("\n", $items);
                    }
                    break;

                case 'blockquote':
                    $text = $this->inlineText($children);

                    if ($text !== '') {
                        $blocks[] = '> '.$text;
                    }
                    break;

                case 'codeBlock':
                    $text = $this->inlineText($children);

                    if ($text !== '') {
                        $blocks[] = $text;
                    }
                    break;

                case 'alert':
                    $html = $node['attrs']['data']['content']
                        ?? $node['attrs']['state']['content']
                        ?? $node['attrs']['content']
                        ?? null;

                    $text = is_string($html) ? trim(strip_tags($html)) : '';

                    if ($children !== []) {
                        $text = trim($text.' '.$this->inlineText($children));
                    }

                    if ($text !== '') {
                        $blocks[] = $text;
                    }
                    break;

                default:
                    $text = $this->inlineText($children);

                    if ($text !== '') {
                        $blocks[] = $text;
                    }
                    break;
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Plain text of a node subtree, joining nested blocks with spaces.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private function inlineText(array $nodes): string
    {
        $parts = [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'text') {
                $parts[] = (string) ($node['text'] ?? '');

                continue;
            }

            if (is_array($node['content'] ?? null)) {
                $parts[] = $this->inlineText($node['content']);
            }
        }

        $joined = preg_replace('/\s+/u', ' ', implode(' ', $parts));

        return trim($joined ?? '');
    }
}
