<?php

namespace App\Services\Telegram\Handlers;

use App\Models\Page;
use Telegram\Bot\Objects\Message;

class UquccListHandler extends BaseHandler
{
    public function handle(Message $message): void
    {
        if (!$this->matches($message, '/^الفهرس$/u')) {
            return;
        }

        $this->listPages($message);
    }

    protected function listPages(Message $message): void
    {
        // Get all visible root-level pages with their children
        $pages = Page::visible()
            ->rootLevel()
            ->with(['children' => function ($query) {
                $query->where('hidden', false)->orderBy('order');
            }])
            ->orderBy('order')
            ->get();

        if ($pages->isEmpty()) {
            $this->reply($message, 'لا توجد صفحات متاحة حالياً');
            return;
        }

        $list = [];
        foreach ($pages as $page) {
            $list[] = "`دليل {$page->title}`";

            // Add children if they exist
            if ($page->children->isNotEmpty()) {
                foreach ($page->children as $child) {
                    $list[] = "  ↳ `دليل {$child->title}`";
                }
            }
        }

        $this->replyMarkdown($message, $this->escapeMarkdownV2("الفهرس:\n\n") . implode("\n", $list));
    }
}
