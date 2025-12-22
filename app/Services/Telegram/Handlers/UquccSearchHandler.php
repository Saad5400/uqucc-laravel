<?php

namespace App\Services\Telegram\Handlers;

use App\Models\Page;
use App\Services\QuickResponseService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Telegram\Bot\Objects\Message;

class UquccSearchHandler extends BaseHandler
{
    protected array $exceptionWords = ['لابتوب'];

    public function __construct(
        \Telegram\Bot\Api $telegram,
        protected QuickResponseService $quickResponses
    ) {
        parent::__construct($telegram);
    }

    public function handle(Message $message): void
    {
        $content = trim($message->getText() ?? '');

        // Check if it matches "دليل <query>" or is one of the exception words
        $isCommand = false;
        $query = null;

        if (preg_match('/^دليل\s+(.+)$/u', $content, $matches)) {
            $isCommand = true;
            $query = $matches[1];
        } elseif (in_array($content, $this->exceptionWords)) {
            $isCommand = true;
            $query = $content;
        }

        if (! $isCommand) {
            return;
        }

        $this->searchAndRespond($message, $query);
    }

    protected function searchAndRespond(Message $message, string $query): void
    {
        $page = $this->quickResponses->search($query);

        if (! $page) {
            $this->reply($message, 'الصفحة غير موجودة');

            return;
        }

        $this->sendPageResult($message, $page);
    }

    protected function sendPageResult(Message $message, Page $page): void
    {
        $pageUrl = url($page->slug);

        $title = $this->escapeMarkdownV2($page->title);
        $lines = ["*{$title}*"];

        if ($page->quick_response_enabled) {
            if ($page->quick_response_message) {
                $lines[] = $this->escapeMarkdownV2($page->quick_response_message);
            }

            if ($page->quick_response_send_link) {
                $lines[] = "🔗 الرابط: [{$pageUrl}]({$pageUrl})";
            }
        } else {
            // Fallback content when quick responses are not configured
            $preview = Str::limit(strip_tags((string) $page->html_content), 300);
            if ($preview) {
                $lines[] = $this->escapeMarkdownV2($preview);
            }

            $lines[] = "🔗 الرابط: [{$pageUrl}]({$pageUrl})";
        }

        $params = [
            'chat_id' => $message->getChat()->getId(),
            'text' => implode("\n\n", array_filter($lines)),
            'parse_mode' => 'MarkdownV2',
        ];

        if ($page->quick_response_buttons) {
            $buttons = collect($page->quick_response_buttons)
                ->filter(fn ($btn) => filled($btn['text'] ?? null) && filled($btn['url'] ?? null))
                ->map(fn ($btn) => [[
                    'text' => $btn['text'],
                    'url' => $btn['url'],
                ]])
                ->values()
                ->all();

            if (! empty($buttons)) {
                $params['reply_markup'] = [
                    'inline_keyboard' => $buttons,
                ];
            }
        }

        $this->telegram->sendMessage($params);

        if ($page->quick_response_enabled) {
            $this->sendQuickResponseAttachments($message, $page);
        }
    }

    protected function sendQuickResponseAttachments(Message $message, Page $page): void
    {
        $attachments = collect($page->quick_response_attachments ?? [])
            ->filter()
            ->values();

        if ($attachments->isEmpty()) {
            return;
        }

        foreach ($attachments as $path) {
            $disk = Storage::disk('public');
            $url = $disk->url($path);
            $mime = $disk->mimeType($path) ?? '';

            $payload = [
                'chat_id' => $message->getChat()->getId(),
            ];

            if (str_starts_with($mime, 'image/')) {
                $payload['photo'] = $url;
                $this->telegram->sendPhoto($payload);
            } else {
                $payload['document'] = $url;
                $this->telegram->sendDocument($payload);
            }
        }
    }
}
