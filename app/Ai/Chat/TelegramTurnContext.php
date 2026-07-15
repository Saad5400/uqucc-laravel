<?php

namespace App\Ai\Chat;

use Illuminate\Support\Str;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\User;

/**
 * Prepends a per-turn context block describing WHO is asking and WHERE — the
 * Telegram sender's name/username, the chat kind (private vs group) and its
 * title, the user's language, and the quoted message when the «سيك …» ask is a
 * reply. The Telegram transport shares one conversation per chat, so this hands
 * the model the identity signals a group chat would otherwise strip.
 *
 * The block lives ONLY in the current turn's user message (the tail after the
 * cached system prompt + tools + prior messages), so prefix caching is never
 * disturbed; each turn's block is built the same way structurally and, once
 * stored in history, stays frozen. It composes as the OUTERMOST wrapper around
 * {@see AttachmentContext} — metadata first, then any attachment evidence, then
 * the visitor's own message — and is framed as context, not as the request.
 *
 * Telegram never replays a stored user message back to a client (only the web
 * chat's history endpoint does, and it owns its own {@see AttachmentContext}
 * unwrap), so no unwrap mirror is needed here.
 */
class TelegramTurnContext
{
    /** First line of every wrapped prompt — frames the block as context. */
    private const SENTINEL = '[سياق المحادثة — معلومات عمّن يسأل وأين، وليست جزءاً من طلبه]';

    /** Marker separating the metadata from the visitor's own message. */
    private const MESSAGE_MARKER = 'رسالة المستخدم:';

    /** Longest quoted reply text kept, in characters, before truncation. */
    private const REPLY_TEXT_LIMIT = 500;

    /**
     * Wrap the visitor's message with a metadata preamble about the sender and
     * chat. With no usable metadata the message passes through unchanged,
     * keeping such turns byte-identical (and provider-cacheable).
     */
    public function wrap(string $message, Message $telegramMessage): string
    {
        $preamble = $this->preambleFor($telegramMessage);

        if ($preamble === '') {
            return $message;
        }

        return self::SENTINEL."\n".$preamble
            ."\n\n".self::MESSAGE_MARKER."\n".$message;
    }

    /**
     * Build the metadata lines for a turn: sender identity, chat kind and
     * title, language, and the quoted reply context. Empty fields are omitted,
     * and the line order is fixed so identical input yields an identical block.
     */
    public function preambleFor(Message $telegramMessage): string
    {
        $lines = array_filter([
            $this->senderLine($telegramMessage->getFrom()),
            $this->chatLine($telegramMessage),
            $this->groupTitleLine($telegramMessage),
            $this->languageLine($telegramMessage->getFrom()),
            $this->replyLine($telegramMessage->getReplyToMessage()),
        ], static fn (string $line): bool => $line !== '');

        return implode("\n", $lines);
    }

    /**
     * "السائل: <name> (@username)" — name and username each omitted when absent.
     */
    private function senderLine(?User $from): string
    {
        if ($from === null) {
            return '';
        }

        $name = $this->displayName($from);
        $username = trim((string) $from->getUsername());

        $identity = trim($name.($username !== '' ? ' (@'.$username.')' : ''));

        return $identity === '' ? '' : 'السائل: '.$identity;
    }

    /**
     * "نوع المحادثة: خاصة | مجموعة | قناة" for the Telegram chat type.
     */
    private function chatLine(Message $telegramMessage): string
    {
        $type = strtolower(trim((string) $telegramMessage->getChat()->getType()));

        $label = match ($type) {
            'private' => 'خاصة',
            'group', 'supergroup' => 'مجموعة',
            'channel' => 'قناة',
            default => '',
        };

        return $label === '' ? '' : 'نوع المحادثة: '.$label;
    }

    /**
     * "اسم المجموعة: <title>" — only for a titled group/channel chat.
     */
    private function groupTitleLine(Message $telegramMessage): string
    {
        $chat = $telegramMessage->getChat();
        $type = strtolower(trim((string) $chat->getType()));

        if ($type === 'private') {
            return '';
        }

        $title = trim((string) $chat->getTitle());

        return $title === '' ? '' : 'اسم المجموعة: '.$title;
    }

    /**
     * "لغة المستخدم: <code>" from the sender's IETF language tag.
     */
    private function languageLine(?User $from): string
    {
        if ($from === null) {
            return '';
        }

        $language = trim((string) $from->getLanguageCode());

        return $language === '' ? '' : 'لغة المستخدم: '.$language;
    }

    /**
     * "ردّاً على رسالة من <name>: «<quoted>»" when this turn replies to another
     * message, with the quoted text truncated to a readable length.
     */
    private function replyLine(?Message $replyTo): string
    {
        if ($replyTo === null) {
            return '';
        }

        $quoted = trim((string) ($replyTo->getText() ?? $replyTo->getCaption()));

        if ($quoted === '') {
            return '';
        }

        $quoted = Str::limit($quoted, self::REPLY_TEXT_LIMIT);
        $author = $this->displayName($replyTo->getFrom());

        $prefix = $author === '' ? 'ردّاً على رسالة' : 'ردّاً على رسالة من '.$author;

        return $prefix.': «'.$quoted.'»';
    }

    /**
     * The sender's display name: first name plus last name when present.
     */
    private function displayName(?User $from): string
    {
        if ($from === null) {
            return '';
        }

        return trim(trim((string) $from->getFirstName()).' '.trim((string) $from->getLastName()));
    }
}
