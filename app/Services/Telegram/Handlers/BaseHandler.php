<?php

namespace App\Services\Telegram\Handlers;

use App\Models\BotCommandStat;
use App\Models\User;
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

    /**
     * Track command usage in statistics
     */
    protected function trackCommand(Message $message, string $commandName): void
    {
        try {
            $telegramId = (string) $message->getFrom()->getId();
            $user = User::findByTelegramId($telegramId);

            BotCommandStat::track(
                commandName: $commandName,
                userId: $user?->id,
                chatType: $message->getChat()->getType(),
                chatId: $message->getChat()->getId()
            );
        } catch (\Exception $e) {
            // Silently fail - don't break the bot
        }
    }

    protected function matches(Message $message, string $pattern): bool
    {
        $text = $message->getText();
        // Ensure getText() returns a string (handle edge cases where it might be an array)
        $content = is_string($text) ? trim($text) : '';

        return preg_match($pattern, $content) === 1;
    }

    protected function reply(Message $message, string $text, ?string $parseMode = null): Message
    {
        $params = [
            'chat_id' => $message->getChat()->getId(),
            'text' => $text,
            'reply_to_message_id' => $message->getMessageId(),
        ];

        if ($parseMode) {
            $params['parse_mode'] = $parseMode;
        }

        return $this->telegram->sendMessage($params);
    }

    protected function replyMarkdown(Message $message, string $text): Message
    {
        return $this->reply($message, $text, 'MarkdownV2');
    }

    protected function replyHtml(Message $message, string $text): Message
    {
        return $this->reply($message, $text, 'HTML');
    }

    protected function replyPhoto(Message $message, string $photoUrl, ?string $caption = null): Message
    {
        $params = [
            'chat_id' => $message->getChat()->getId(),
            'photo' => $photoUrl,
            'reply_to_message_id' => $message->getMessageId(),
        ];

        if ($caption) {
            $params['caption'] = $caption;
            $params['parse_mode'] = 'HTML';
        }

        return $this->telegram->sendPhoto($params);
    }

    /**
     * Delete both the user message and bot response after a delay.
     */
    protected function deleteMessagesAfterDelay(Message $userMessage, Message $botResponse, int $delaySeconds = 5): void
    {
        sleep($delaySeconds);

        $chatId = $userMessage->getChat()->getId();

        // Delete user message
        try {
            $this->telegram->deleteMessage([
                'chat_id' => $chatId,
                'message_id' => $userMessage->getMessageId(),
            ]);
        } catch (\Exception $e) {
            // Silently fail - message might already be deleted or bot lacks permissions
        }

        // Delete bot response
        try {
            $this->telegram->deleteMessage([
                'chat_id' => $chatId,
                'message_id' => $botResponse->getMessageId(),
            ]);
        } catch (\Exception $e) {
            // Silently fail - message might already be deleted or bot lacks permissions
        }
    }

    /**
     * Reply to a message and auto-delete both messages after a delay.
     */
    protected function replyAndDelete(Message $message, string $text, ?string $parseMode = null, int $delaySeconds = 5): void
    {
        $response = $this->reply($message, $text, $parseMode);
        $this->deleteMessagesAfterDelay($message, $response, $delaySeconds);
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
