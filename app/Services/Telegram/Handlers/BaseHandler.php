<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;

abstract class BaseHandler
{
    protected Api $telegram;

    protected array $userStates = [];

    public function __construct(Api $telegram)
    {
        $this->telegram = $telegram;
    }

    abstract public function handle(Message $message): void;

    protected function matches(Message $message, string $pattern): bool
    {
        $text = $message->getText();
        // Ensure getText() returns a string (handle edge cases where it might be an array)
        $content = is_string($text) ? trim($text) : '';

        return preg_match($pattern, $content) === 1;
    }

    protected function reply(Message $message, string $text, ?string $parseMode = null): void
    {
        $params = [
            'chat_id' => $message->getChat()->getId(),
            'text' => $text,
        ];

        if ($parseMode) {
            $params['parse_mode'] = $parseMode;
        }

        $this->telegram->sendMessage($params);
    }

    protected function replyMarkdown(Message $message, string $text): void
    {
        $this->reply($message, $text, 'MarkdownV2');
    }

    protected function replyHtml(Message $message, string $text): void
    {
        $this->reply($message, $text, 'HTML');
    }

    protected function replyPhoto(Message $message, string $photoUrl, ?string $caption = null): void
    {
        $params = [
            'chat_id' => $message->getChat()->getId(),
            'photo' => $photoUrl,
        ];

        if ($caption) {
            $params['caption'] = $caption;
            $params['parse_mode'] = 'HTML';
        }

        $this->telegram->sendPhoto($params);
    }

    /**
     * Escape HTML entities for safe display in Telegram.
     */
    protected function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    protected function getUserState(int $userId): ?array
    {
        return $this->userStates[$userId] ?? null;
    }

    protected function setUserState(int $userId, array $state): void
    {
        $this->userStates[$userId] = $state;
    }

    protected function clearUserState(int $userId): void
    {
        unset($this->userStates[$userId]);
    }

    protected function escapeMarkdownV2(string $text): string
    {
        $specialChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];

        foreach ($specialChars as $char) {
            $text = str_replace($char, '\\'.$char, $text);
        }

        return $text;
    }

    /**
     * Escape URL for use inside markdown link parentheses.
     * In MarkdownV2, only ) and \ need to be escaped inside link URLs.
     */
    protected function escapeMarkdownV2Url(string $url): string
    {
        // Escape backslash first, then closing parenthesis
        $url = str_replace('\\', '\\\\', $url);
        $url = str_replace(')', '\\)', $url);

        return $url;
    }
}
