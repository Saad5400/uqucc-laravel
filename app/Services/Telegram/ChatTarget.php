<?php

namespace App\Services\Telegram;

/**
 * One place the bot posts to: a Telegram chat and, optionally, a forum topic
 * within it. Configured as a plain string in the settings — either a bare chat
 * id («-1002195627011») or chat:topic («-1002195627011:42») for a group that
 * uses Telegram topics — and parsed here into the outgoing
 * `chat_id` / `message_thread_id` pair.
 *
 * Shared by every scheduled broadcast (the daily quiz, the opinion poll), so
 * an admin who learns the «chat:topic» form once knows it everywhere.
 */
final class ChatTarget
{
    public function __construct(
        public readonly int $chatId,
        public readonly ?int $threadId = null,
    ) {}

    /**
     * Parse a configured "chat_id" or "chat_id:thread_id" string. A blank or
     * zero thread segment is treated as "no topic".
     */
    public static function parse(string $value): self
    {
        [$chat, $thread] = array_pad(explode(':', trim($value), 2), 2, null);

        return new self(
            (int) $chat,
            is_string($thread) && trim($thread) !== '' ? (int) $thread : null,
        );
    }

    /**
     * Parse a whole configured list, dropping its keys so callers can iterate
     * it positionally.
     *
     * @param  array<int|string, string>  $values
     * @return array<int, self>
     */
    public static function parseAll(array $values): array
    {
        return array_map(self::parse(...), array_values($values));
    }

    /**
     * Merge this target's `chat_id` (and `message_thread_id` when it is a forum
     * topic) into an outgoing Bot API parameter array.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function apply(array $params): array
    {
        $params['chat_id'] = $this->chatId;

        if ($this->threadId !== null) {
            $params['message_thread_id'] = $this->threadId;
        }

        return $params;
    }
}
