<?php

namespace App\Services\Quiz;

/**
 * One place the quiz is posted to: a Telegram chat and, optionally, a forum
 * topic within it. Configured as a plain string in {@see \App\Settings\QuizSettings}
 * — either a bare chat id («-1002195627011») or chat:topic («-1002195627011:42»)
 * for a group that uses Telegram topics — and parsed here into the outgoing
 * `chat_id` / `message_thread_id` pair.
 */
final class QuizTarget
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
