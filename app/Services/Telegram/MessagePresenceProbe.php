<?php

namespace App\Services\Telegram;

use Telegram\Bot\Api;
use Throwable;

/**
 * Answers "is this message still in the chat?" — the Bot API never tells us.
 *
 * Telegram sends no update when a user deletes a message in a normal chat
 * (`deleted_business_messages` covers business accounts only), so the only way
 * to learn about a deletion is to ask about the message and read the error.
 * Both probes below are silent: they change nothing the chat can see and are
 * safe to repeat.
 *
 * 1. `setMessageReaction` with an empty reaction list — a documented no-op that
 *    removes the bot's own (absent) reaction. It succeeds for a live message
 *    and fails with «message to react not found» for a deleted one. In chats
 *    where reactions are restricted it fails for an unrelated reason, hence:
 * 2. `editMessageReplyMarkup` — always an error for someone else's message, but
 *    a *telling* one: «message can't be edited» (it exists) versus «message to
 *    edit not found» (it is gone).
 *
 * A verdict of null means neither probe was conclusive; callers must treat that
 * as "unknown", never as a deletion.
 */
class MessagePresenceProbe
{
    /** Error fragments that mean the message id no longer resolves. */
    protected const MISSING_ERRORS = [
        'message to react not found',
        'message to edit not found',
        'message to reply not found',
        'message not found',
        'message_id_invalid',
    ];

    /** Error fragments that only a message which still exists can produce. */
    protected const PRESENT_ERRORS = [
        "message can't be edited",
        'message is not modified',
    ];

    public function __construct(protected Api $telegram) {}

    /**
     * True when the message is gone, false when it is still there, null when
     * the API answered in a way that settles neither.
     */
    public function isDeleted(int $chatId, int $messageId): ?bool
    {
        $verdict = $this->probeWithReaction($chatId, $messageId);

        return $verdict ?? $this->probeWithReplyMarkup($chatId, $messageId);
    }

    protected function probeWithReaction(int $chatId, int $messageId): ?bool
    {
        try {
            $this->telegram->setMessageReaction([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reaction' => [],
            ]);

            return false;
        } catch (Throwable $exception) {
            return $this->verdictFrom($exception->getMessage());
        }
    }

    protected function probeWithReplyMarkup(int $chatId, int $messageId): ?bool
    {
        try {
            $this->telegram->editMessageReplyMarkup([
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);

            return false;
        } catch (Throwable $exception) {
            return $this->verdictFrom($exception->getMessage());
        }
    }

    protected function verdictFrom(string $error): ?bool
    {
        $error = mb_strtolower($error);

        foreach (self::MISSING_ERRORS as $fragment) {
            if (str_contains($error, $fragment)) {
                return true;
            }
        }

        foreach (self::PRESENT_ERRORS as $fragment) {
            if (str_contains($error, $fragment)) {
                return false;
            }
        }

        return null;
    }
}
