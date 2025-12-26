<?php

namespace App\Services\Telegram\Handlers;

use App\Models\Page;
use Telegram\Bot\Objects\Message;

class UquccListHandler extends BaseHandler
{
    public function handle(Message $message): void
    {
        if (! $this->matches($message, '/^الفهرس$/u')) {
            return;
        }

        $this->listPages($message);
    }

    protected function listPages(Message $message): void
    {
        // Get all visible (in bot) root-level pages
        $pages = Page::visibleInBot()
            ->rootLevel()
            ->orderBy('order')
            ->get();

        if ($pages->isEmpty()) {
            $this->reply($message, 'لا توجد صفحات متاحة حالياً');

            return;
        }

        $list = [];
        foreach ($pages as $page) {
            $this->addPageToList($page, $list, 0);
        }

        $this->replyHtml($message, "<b>الفهرس:</b>\n\n".implode("\n", $list));
    }

    protected function addPageToList(Page $page, array &$list, int $level): void
    {
        // Add indentation based on level
        $indent = str_repeat('  ', $level);
        $arrow = $level > 0 ? '| ' : '';
        $escapedTitle = $this->escapeHtml($page->title);

        $list[] = "{$indent}{$arrow}<code>دليل {$escapedTitle}</code>";

        // Recursively add all visible (in bot) children
        $children = $page->children()->where('hidden_from_bot', false)->orderBy('order')->get();

        foreach ($children as $child) {
            $this->addPageToList($child, $list, $level + 1);
        }
    }
}
