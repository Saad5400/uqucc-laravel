<?php

namespace App\Services\Telegram;

/**
 * A page's reply, composed and ready to send: the text with its markup, the
 * keyboard under it, the files that go before it, and whether Telegram should
 * draw the page's link preview.
 *
 * @see PageReplyComposer for how each part is decided
 */
final readonly class PageReply
{
    /**
     * @param  string  $text  Telegram HTML: title, the content in a collapsed quote, and the footer lines.
     * @param  string|null  $fallbackText  The same reply with the content unquoted, for a Telegram that refuses the quoted markup; null when there is nothing to fall back to.
     * @param  array<int, array<int, array{text: string, url: string}>>  $keyboard  Inline keyboard rows.
     * @param  list<string>  $attachments  Media disk paths or external URLs, sent before the text.
     * @param  string|null  $previewUrl  The page URL for Telegram to preview under the text; null keeps the reply compact.
     */
    public function __construct(
        public string $text,
        public ?string $fallbackText,
        public array $keyboard,
        public array $attachments,
        public ?string $previewUrl,
    ) {}

    /**
     * The keyboard as the Bot API wants it — a JSON string — or null for none.
     */
    public function replyMarkup(): ?string
    {
        if ($this->keyboard === []) {
            return null;
        }

        return json_encode(['inline_keyboard' => $this->keyboard]);
    }

    /**
     * Telegram's link preview, as a JSON string: the page's own card drawn large
     * for a reply that sends its reader to the website, disabled for the rest so
     * a reply with three links in it does not grow three previews.
     */
    public function linkPreviewOptions(): string
    {
        if ($this->previewUrl === null) {
            return json_encode(['is_disabled' => true]);
        }

        return json_encode([
            'url' => $this->previewUrl,
            'prefer_large_media' => true,
            'show_above_text' => false,
        ]);
    }
}
