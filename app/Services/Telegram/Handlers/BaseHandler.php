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
        return preg_match($pattern, trim($message->getText() ?? '')) === 1;
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

    protected function replyPhoto(Message $message, string $photoUrl, ?string $caption = null): void
    {
        $params = [
            'chat_id' => $message->getChat()->getId(),
            'photo' => $photoUrl,
        ];

        if ($caption) {
            $params['caption'] = $caption;
            $params['parse_mode'] = 'MarkdownV2';
        }

        $this->telegram->sendPhoto($params);
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
            $text = str_replace($char, '\\' . $char, $text);
        }

        return $text;
    }
}
