<?php

namespace App\Services\Telegram\Handlers;

use App\Models\Page;
use Illuminate\Support\Str;
use Telegram\Bot\Objects\Message;

class UquccSearchHandler extends BaseHandler
{
    protected array $exceptionWords = ['لابتوب'];

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
        // Search directly in pages table
        $page = Page::visible()
            ->where(function ($q) use ($query) {
                $q->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower($query).'%'])
                    ->orWhereRaw('LOWER(description) LIKE ?', ['%'.mb_strtolower($query).'%'])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ['%'.mb_strtolower($query).'%']);
            })
            ->first();

        if (! $page) {
            $this->reply($message, 'الصفحة غير موجودة');

            return;
        }

        $this->sendPageResult($message, $page);
    }

    protected function sendPageResult(Message $message, Page $page): void
    {
        $pageUrl = url($page->slug);

        // Build message content with proper markdown escaping
        $title = $this->escapeMarkdownV2($page->title);
        $messageText = "*{$title}*\n\n";

        // Add description
        if ($page->description) {
            $content = Str::limit($page->description, 500);
            $messageText .= $this->escapeMarkdownV2($content)."\n\n";
        }

        // Add link with proper escaping
        $escapedUrl = $this->escapeMarkdownV2($pageUrl);
        $messageText .= "🔗 الرابط: [{$escapedUrl}]({$pageUrl})";

        $this->replyMarkdown($message, $messageText);
    }
}
