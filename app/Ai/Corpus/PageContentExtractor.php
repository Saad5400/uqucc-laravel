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
 *
 * Images surface as clearly-marked blocks at their position in the page:
 * their alt text always, plus the vision-model transcription supplied by
 * {@see PageImageExtractor} when one is available. extract() only reads the
 * extraction cache; extractForIngestion() may trigger new (paid) OCR, so it
 * is reserved for the ingestion pipeline. The collaborator is resolved
 * lazily so plain `new PageContentExtractor` (the admin copilot's usage)
 * keeps working unchanged.
 */
class PageContentExtractor
{
    private ?PageImageExtractor $imageExtractor = null;

    private function imageExtractor(): PageImageExtractor
    {
        return $this->imageExtractor ??= app(PageImageExtractor::class);
    }

    public function extract(Page $page): string
    {
        return trim('# '.trim($page->title)."\n\n".$this->markdownFromContent($page->html_content));
    }

    /**
     * Like extract(), but allowed to OCR still-uncached images through the
     * vision model (spend-recorded, cache-backed). Ingestion-only: every
     * other caller reads the cache via extract().
     */
    public function extractForIngestion(Page $page): string
    {
        return trim('# '.trim($page->title)."\n\n".$this->markdownFromContent($page->html_content, ocrImages: true));
    }

    /**
     * Markdown for a raw html_content value (TipTap array or legacy string),
     * WITHOUT the title heading extract() prepends. Public because the admin
     * copilot needs the same flattening for editor state that is not saved
     * to a Page yet.
     *
     * @param  array<string, mixed>|string|null  $content
     */
    public function markdownFromContent(array|string|null $content, bool $ocrImages = false): string
    {
        return is_array($content)
            ? $this->tipTapToMarkdown($content['content'] ?? [], $ocrImages)
            : trim((string) $content);
    }

    /**
     * Flatten TipTap nodes to markdown. Only the block structure retrieval
     * cares about is preserved (headings, paragraphs, lists, quotes, code);
     * everything else contributes its plain text. Images found anywhere in a
     * node's subtree (they live inline inside paragraphs, or as <img> tags in
     * custom-block HTML) are appended right after that node's block, keeping
     * their reading position.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private function tipTapToMarkdown(array $nodes, bool $ocrImages = false): string
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

            foreach ($this->imagesIn([$node]) as $image) {
                $block = $this->imageBlock($image['src'], $image['alt'], $ocrImages);

                if ($block !== '') {
                    $blocks[] = $block;
                }
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * The markdown block one image contributes: its transcription under a
     * position marker when text is available, its alt text alone otherwise,
     * nothing when it carries neither.
     */
    private function imageBlock(string $src, string $alt, bool $ocrImages): string
    {
        $text = $src === '' ? null : $this->imageExtractor()->extractedTextFor($src, $ocrImages);

        if ($text !== null && $text !== '') {
            $label = $alt !== '' ? $alt : basename((string) (parse_url($src, PHP_URL_PATH) ?: $src));

            return '[محتوى صورة: '.$label."]\n".$text;
        }

        return $alt !== '' ? '[صورة: '.$alt.']' : '';
    }

    /**
     * Every image in a node subtree, in document order: TipTap `image` nodes
     * (stored inline inside paragraphs) plus <img> tags embedded in the HTML
     * strings of customBlock/alert attrs — the FROZEN custom-block contract
     * stores HTML inside attrs.config.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array{src: string, alt: string}>
     */
    private function imagesIn(array $nodes): array
    {
        $images = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? null;

            if ($type === 'image') {
                $images[] = [
                    'src' => trim((string) ($node['attrs']['src'] ?? '')),
                    'alt' => trim((string) ($node['attrs']['alt'] ?? '')),
                ];
            }

            if ($type === 'customBlock' || $type === 'alert') {
                array_push($images, ...$this->imagesInHtml($node['attrs'] ?? []));
            }

            if (is_array($node['content'] ?? null)) {
                array_push($images, ...$this->imagesIn($node['content']));
            }
        }

        return $images;
    }

    /**
     * <img> tags inside custom-block attr values, which nest HTML strings at
     * arbitrary depth (attrs.config.content, attrs.config.answer, ...).
     *
     * @return list<array{src: string, alt: string}>
     */
    private function imagesInHtml(mixed $value): array
    {
        if (is_array($value)) {
            $images = [];

            foreach ($value as $nested) {
                array_push($images, ...$this->imagesInHtml($nested));
            }

            return $images;
        }

        if (! is_string($value) || ! str_contains($value, '<img')) {
            return [];
        }

        preg_match_all('/<img\b[^>]*>/iu', $value, $matches);

        $images = [];

        foreach ($matches[0] as $tag) {
            $images[] = [
                'src' => trim($this->htmlAttribute($tag, 'src')),
                'alt' => trim($this->htmlAttribute($tag, 'alt')),
            ];
        }

        return $images;
    }

    private function htmlAttribute(string $tag, string $attribute): string
    {
        if (preg_match('/\b'.$attribute.'\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/iu', $tag, $match) !== 1) {
            return '';
        }

        return html_entity_decode($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
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
