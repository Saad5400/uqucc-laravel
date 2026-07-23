<?php

namespace App\Ai\Copilot;

use App\Ai\Corpus\PageContentExtractor;
use Illuminate\Support\Str;
use Tiptap\Editor;
use Tiptap\Extensions\StarterKit;
use Tiptap\Marks\Link;

/**
 * Converts between the Page editor's TipTap JSON document and the markdown
 * the copilot's language model reads and writes.
 *
 * Reading (toMarkdown) reuses {@see PageContentExtractor} — the exact same
 * flattening the AI corpus ingests, so the model sees pages the way search
 * already does. Writing (toDocument/append) goes markdown → HTML (CommonMark
 * via Str::markdown) → TipTap JSON (via ueberdosis/tiptap-php).
 */
class TipTapContent
{
    /**
     * Flatten an html_content value (TipTap array or legacy string) to markdown.
     *
     * @param  array<string, mixed>|string|null  $content
     */
    public static function toMarkdown(array|string|null $content): string
    {
        return (new PageContentExtractor)->markdownFromContent($content);
    }

    /**
     * Build a full TipTap document from markdown.
     *
     * @return array<string, mixed>
     */
    public static function toDocument(string $markdown): array
    {
        $html = (string) Str::markdown($markdown);

        return self::normalize(self::editor()->setContent($html)->getDocument());
    }

    /**
     * Append markdown as new blocks after the current editor content.
     *
     * @param  array<string, mixed>|string|null  $current
     * @return array<string, mixed>
     */
    public static function append(array|string|null $current, string $markdown): array
    {
        $document = self::currentDocument($current);
        $appended = self::toDocument($markdown);

        $document['content'] = array_merge(
            array_values($document['content'] ?? []),
            $appended['content'] ?? [],
        );

        return $document;
    }

    /**
     * Coerce the current editor state into a TipTap document array. Legacy
     * string content (pre-TipTap pages) is parsed as HTML when it looks like
     * markup, otherwise rendered from markdown.
     *
     * @param  array<string, mixed>|string|null  $content
     * @return array<string, mixed>
     */
    private static function currentDocument(array|string|null $content): array
    {
        if (is_array($content)) {
            return $content;
        }

        $text = trim((string) $content);

        if ($text === '') {
            return ['type' => 'doc', 'content' => []];
        }

        $html = str_contains($text, '<') ? $text : (string) Str::markdown($text);

        return self::normalize(self::editor()->setContent($html)->getDocument());
    }

    /**
     * The default tiptap-php StarterKit has no Link mark, which silently
     * strips every anchor on markdown → document conversion.
     */
    private static function editor(): Editor
    {
        return new Editor([
            'extensions' => [new StarterKit, new Link],
        ]);
    }

    /**
     * Fix up tiptap-php output for the editor's schema: list items must wrap
     * their inline children in a paragraph node (the converter emits bare
     * text nodes directly under listItem).
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function normalize(array $node): array
    {
        if (! is_array($node['content'] ?? null)) {
            return $node;
        }

        $node['content'] = array_map(
            fn (array $child): array => self::normalize($child),
            array_values($node['content']),
        );

        if (($node['type'] ?? null) === 'listItem' && self::containsInlineNodes($node['content'])) {
            $node['content'] = [[
                'type' => 'paragraph',
                'content' => $node['content'],
            ]];
        }

        return $node;
    }

    /**
     * @param  list<array<string, mixed>>  $children
     */
    private static function containsInlineNodes(array $children): bool
    {
        foreach ($children as $child) {
            if (($child['type'] ?? null) === 'text') {
                return true;
            }
        }

        return false;
    }
}
