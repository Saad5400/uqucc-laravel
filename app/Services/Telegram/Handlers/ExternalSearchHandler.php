<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Objects\Message;

class ExternalSearchHandler extends BaseHandler
{
    public function handle(Message $message): void
    {
        $text = $message->getText();
        // Ensure getText() returns a string (handle edge cases where it might be an array)
        $content = is_string($text) ? trim($text) : '';

        if (empty($content)) {
            return;
        }

        // Check for "قوقل <query>" pattern
        if (preg_match('/^قوقل\s+(.+)$/u', $content, $matches)) {
            $query = $matches[1];
            $this->handleGoogleSearch($message, $query);

            return;
        }

        // Check for "قيم <query>" pattern
        if (preg_match('/^قيم\s+(.+)$/u', $content, $matches)) {
            $query = $matches[1];
            $this->handleQeeemSearch($message, $query);

            return;
        }
    }

    /**
     * Handle Google search command.
     */
    protected function handleGoogleSearch(Message $message, string $query): void
    {
        $this->trackCommand($message, 'قوقل');

        // URL encode the query for Google search
        $encodedQuery = urlencode($query);
        $googleUrl = "https://www.google.com/search?q={$encodedQuery}";

        // Escape the query for display in HTML
        $escapedQuery = $this->escapeHtml($query);
        $escapedUrl = $this->escapeHtml($googleUrl);

        // Send the response with the Google search link
        $responseText = "🔍 <b>بحث Google عن:</b> {$escapedQuery}\n\n";
        $responseText .= "🔗 <a href=\"{$escapedUrl}\">افتح النتائج</a>";

        $this->replyHtml($message, $responseText);
    }

    /**
     * Handle Qeeem search command.
     */
    protected function handleQeeemSearch(Message $message, string $query): void
    {
        $this->trackCommand($message, 'قيم');

        // URL encode the query for Qeeem search
        $encodedQuery = urlencode($query);
        $qeeemUrl = "https://qeeem.com/uqu/search?name={$encodedQuery}";

        // Escape the query for display in HTML
        $escapedQuery = $this->escapeHtml($query);
        $escapedUrl = $this->escapeHtml($qeeemUrl);

        // Send the response with the Qeeem search link
        $responseText = "⭐ <b>بحث قييم عن:</b> {$escapedQuery}\n\n";
        $responseText .= "🔗 <a href=\"{$escapedUrl}\">افتح النتائج</a>";

        $this->replyHtml($message, $responseText);
    }
}
