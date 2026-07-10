<?php

namespace App\Models;

use Database\Factories\TelegramChatSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-chat settings for the Telegram bot's AI assistant. One row per Telegram
 * chat (private, group, or supergroup); the assistant is inactive unless the
 * chat's row exists with ai_enabled=true. title/type are snapshots taken when
 * the chat toggled the assistant, for admin-panel visibility only.
 *
 * @property int $id
 * @property int $chat_id
 * @property bool $ai_enabled
 * @property string|null $title
 * @property string|null $type
 * @property string|null $enabled_by
 * @property string|null $conversation_id
 */
class TelegramChatSetting extends Model
{
    /** @use HasFactory<TelegramChatSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'ai_enabled',
        'title',
        'type',
        'enabled_by',
        'conversation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'ai_enabled' => 'boolean',
        ];
    }

    /**
     * The settings row for a chat, if any.
     */
    public static function forChat(int|string $chatId): ?self
    {
        return static::query()->where('chat_id', (int) $chatId)->first();
    }

    /**
     * Whether the AI assistant is activated for a chat.
     */
    public static function isAiEnabledForChat(int|string $chatId): bool
    {
        return static::query()
            ->where('chat_id', (int) $chatId)
            ->where('ai_enabled', true)
            ->exists();
    }
}
