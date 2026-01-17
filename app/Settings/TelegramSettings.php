<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class TelegramSettings extends Settings
{
    /** @var array<string> */
    public array $page_management_allowed_chat_ids;

    public bool $page_management_auto_delete_messages;

    public static function group(): string
    {
        return 'telegram';
    }

    /**
     * Check if a chat ID is allowed for page management.
     * Empty array means all chats are allowed.
     */
    public function isChatAllowedForPageManagement(int|string $chatId): bool
    {
        if (empty($this->page_management_allowed_chat_ids)) {
            return true;
        }

        return in_array($chatId, $this->page_management_allowed_chat_ids, false)
            || in_array((string) $chatId, $this->page_management_allowed_chat_ids, true)
            || in_array((int) $chatId, $this->page_management_allowed_chat_ids, true);
    }
}
