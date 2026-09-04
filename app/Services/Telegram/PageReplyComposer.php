<?php

namespace App\Services\Telegram;

use App\Models\Page;
use App\Services\TipTapContentExtractor;
use App\Support\TelegramHtml;

/**
 * Composes the bot's reply for a page.
 *
 * A reply is one text message, read in a group, so it is shaped to be skimmed
 * and expanded rather than scrolled past: the page's title on top, linked to
 * the website; the page's content under it, folded into a collapsed quote that
 * opens on a tap when it is tall enough to bury the conversation and left
 * plainly in the message when it is not; and a footer that says where the rest
 * is when the message could not carry it all. The page's links and sub-pages
 * are buttons under the text, and the page's few images go before it as an
 * album.
 *
 * A page that is mostly pictures — a tutorial of screenshots — is the one case
 * the text cannot stand in for. Its reply keeps the text, drops the album, and
 * lets Telegram draw the page's own link preview instead, so the reader gets a
 * picture and a way to the website in one message.
 *
 * Each part of the content comes from the page's own document or from what the
 * admin wrote for the bot, per the page's `quick_response_auto_extract_*`
 * settings: "from the page" means the extractor's reading, otherwise the custom
 * value stored on the page.
 */
class PageReplyComposer
{
    /** Images a page may have before its reply stops carrying them and points to the website instead. */
    public const MAX_INLINE_IMAGES = 4;

    /**
     * How many sub-pages get a button of their own before the rest are folded
     * into one «show all» button that opens the section on the website.
     */
    public const MAX_CHILD_BUTTONS = 10;

    /**
     * Visible characters a reply may hold. Telegram allows 4096; the margin
     * covers what it counts as two (an emoji) and this side counts as one.
     */
    private const MESSAGE_LIMIT = 4000;

    /**
     * Characters a line of a message holds on a phone before Telegram wraps
     * it, for the estimate of how tall the content will be.
     */
    private const CHARS_PER_LINE = 40;

    /**
     * Lines the content may take before it is folded into a collapsed quote.
     * Around four wrapping paragraphs — a message a reader takes in at once,
     * with the group's conversation still visible under it.
     */
    private const MAX_UNQUOTED_LINES = 14;

    public function __construct(
        private readonly TipTapContentExtractor $extractor,
        private readonly ContentParser $contentParser,
    ) {}

    public function compose(Page $page): PageReply
    {
        $extracted = $this->extractor->getExtractedContent($page);
        $content = $this->resolveContent($page, $extracted);

        $heavy = $extracted['images'] > self::MAX_INLINE_IMAGES;
        $linkable = ! $page->hidden;
        $url = url($page->slug);

        $title = $this->titleLine($page, $linkable && $page->quick_response_send_link ? $url : null);
        $body = $this->body($content['message']);

        $footer = [];

        if ($heavy && $linkable) {
            $footer[] = '🖼 <a href="'.$this->escape($url).'">الصور والخطوات المصوّرة في الموقع</a>';
        }

        $readMore = $linkable ? '📖 <a href="'.$this->escape($url).'">تابع القراءة في الموقع</a>' : null;
        $budget = self::MESSAGE_LIMIT
            - TelegramHtml::length($title)
            - TelegramHtml::length(implode("\n\n", $footer))
            - ($readMore === null ? 0 : TelegramHtml::length($readMore))
            - 8;

        if ($body !== '' && TelegramHtml::length($body) > $budget) {
            $body = TelegramHtml::truncate($body, $budget);

            if ($readMore !== null) {
                array_unshift($footer, $readMore);
            }
        }

        $quoted = match (true) {
            $body === '' => null,
            str_contains($body, '<blockquote') => $body,
            $this->displayLines($body) <= self::MAX_UNQUOTED_LINES => $body,
            default => '<blockquote expandable>'.$body.'</blockquote>',
        };

        $text = implode("\n\n", array_filter([$title, $quoted, ...$footer]));
        $fallbackText = $quoted !== null && $quoted !== $body
            ? implode("\n\n", array_filter([$title, $body, ...$footer]))
            : null;

        $attachments = $heavy && $page->quick_response_auto_extract_attachments
            ? []
            : array_values(array_filter($content['attachments'], fn ($attachment): bool => is_string($attachment) && $attachment !== ''));

        return new PageReply(
            text: $text,
            fallbackText: $fallbackText,
            keyboard: [...$this->contentButtonRows($content['buttons']), ...$this->childPageRows($page)],
            attachments: $attachments,
            previewUrl: $heavy && $linkable ? $url : null,
        );
    }

    /**
     * Each field from the page's own content or from the admin's custom value,
     * as the page's auto-extract settings say.
     *
     * @param  array{message: string|null, buttons: array, attachments: array, images: int}  $extracted
     * @return array{message: string|null, buttons: array, attachments: array}
     */
    protected function resolveContent(Page $page, array $extracted): array
    {
        return [
            'message' => $page->quick_response_auto_extract_message
                ? $extracted['message']
                : $page->quick_response_message,
            'buttons' => $page->quick_response_auto_extract_buttons
                ? $extracted['buttons']
                : ($page->quick_response_buttons ?? []),
            'attachments' => $page->quick_response_auto_extract_attachments
                ? $extracted['attachments']
                : ($page->quick_response_attachments ?? []),
        ];
    }

    /**
     * The page's title in bold, linked to the website when a link is wanted.
     */
    protected function titleLine(Page $page, ?string $url): string
    {
        $title = '<b>'.$this->escape((string) $page->title).'</b>';

        return $url === null ? $title : '<a href="'.$this->escape($url).'">'.$title.'</a>';
    }

    /**
     * The content as Telegram HTML with its dates resolved, or an empty string
     * when there is nothing visible in it — an editor's empty paragraph included.
     */
    protected function body(mixed $message): string
    {
        if (! is_string($message) || trim($message) === '') {
            return '';
        }

        $html = $this->cleanHtml($this->contentParser->processDates($message));

        return TelegramHtml::isBlank($html) ? '' : $html;
    }

    /**
     * How many lines the content will take once Telegram has drawn it.
     *
     * Height, not length, is what makes content worth folding away: a wall of
     * text pushes the group's conversation off the screen, while a few lines
     * are read where they are and a quote would only put a tap in the way. So
     * what gets counted is the lines the reader will see — the content's own,
     * plus the ones wrapping adds to every line wider than a phone.
     */
    protected function displayLines(string $body): int
    {
        $lines = 0;

        foreach (explode("\n", $body) as $line) {
            $lines += max(1, (int) ceil(TelegramHtml::length($line) / self::CHARS_PER_LINE));
        }

        return $lines;
    }

    /**
     * Reduce editor HTML to the tags Telegram renders, with paragraph and line
     * breaks written out as newlines.
     */
    protected function cleanHtml(string $html): string
    {
        $html = preg_replace('/<(!DOCTYPE|html|head|body)[^>]*>/i', '', $html);
        $html = preg_replace('/<\/(html|head|body)>/i', '', $html);

        $html = preg_replace('/<p\b[^>]*>/i', '', $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

        $html = strip_tags($html, '<b><strong><i><em><u><s><strike><del><code><pre><a><blockquote>');

        $html = preg_replace('/<(\/?)strong>/i', '<$1b>', $html);
        $html = preg_replace('/<(\/?)em>/i', '<$1i>', $html);
        $html = preg_replace('/<(\/?)(strike|del)>/i', '<$1s>', $html);

        $html = preg_replace('/\n{3,}/', "\n\n", $html);

        return trim($html);
    }

    /**
     * Keyboard rows for the page's own buttons, grouped by their declared size:
     * a run of half-width buttons fills rows of two, a run of third-width rows
     * of three, and a full-width button is a row by itself.
     *
     * @param  array  $buttonsData  The resolved buttons array
     * @return array<int, array<int, array{text: string, url: string}>>
     */
    protected function contentButtonRows(array $buttonsData): array
    {
        $buttons = collect($buttonsData)
            ->filter(function ($button): bool {
                if (! is_array($button)) {
                    return false;
                }

                return filled($button['text'] ?? null) && filled($button['url'] ?? null)
                    && is_string($button['text']) && is_string($button['url']);
            })
            ->map(fn (array $button): array => [
                'text' => $button['text'],
                'url' => $button['url'],
                'size' => $button['size'] ?? 'full',
            ])
            ->values()
            ->all();

        $rows = [];
        $row = [];
        $rowSize = null;
        $perRow = 0;

        foreach ($buttons as $button) {
            $size = $button['size'];

            if ($row !== [] && $rowSize !== $size) {
                $rows[] = $row;
                $row = [];
            }

            if ($row === []) {
                $rowSize = $size;
                $perRow = match ($size) {
                    'half' => 2,
                    'third' => 3,
                    default => 1,
                };
            }

            $row[] = ['text' => $button['text'], 'url' => $button['url']];

            if (count($row) >= $perRow) {
                $rows[] = $row;
                $row = [];
            }
        }

        if ($row !== []) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Keyboard rows for the sub-pages a reply may link to — visible in the bot
     * AND on the website, since the button is a URL and a hidden page's URL
     * 404s. One full-width button each, so an Arabic title is read rather than
     * clipped, capped at MAX_CHILD_BUTTONS with a «show all» button to the
     * section page when there are more.
     *
     * @return array<int, array<int, array{text: string, url: string}>>
     */
    protected function childPageRows(Page $page): array
    {
        $children = $page->children()
            ->visible()
            ->visibleInBot()
            ->get(['id', 'slug', 'title']);

        $rows = $children
            ->take(self::MAX_CHILD_BUTTONS)
            ->map(fn (Page $child): array => [[
                'text' => (string) $child->title,
                'url' => url($child->slug),
            ]])
            ->all();

        if ($children->count() > self::MAX_CHILD_BUTTONS && ! $page->hidden) {
            $rows[] = [[
                'text' => 'عرض كل الصفحات ('.$children->count().')',
                'url' => url($page->slug),
            ]];
        }

        return $rows;
    }

    protected function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
