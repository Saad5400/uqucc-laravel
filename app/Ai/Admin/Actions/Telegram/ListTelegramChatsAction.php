<?php

namespace App\Ai\Admin\Actions\Telegram;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\TelegramChatSetting;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The operator's view of the bot's per-chat AI settings — every
 * {@see TelegramChatSetting} row with its Telegram chat_id, snapshot title and
 * type, whether the assistant is enabled, and whether a live conversation is in
 * progress. Mirrors {@see \App\Http\Controllers\Manage\TelegramChatSettingController::index()}.
 * Use the returned chat_id values for set_telegram_chat_ai, reset_telegram_chat
 * and delete_telegram_chat. Read-only.
 */
class ListTelegramChatsAction extends AdminAction
{
    public function name(): string
    {
        return 'list_telegram_chats';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'telegram';
    }

    public function description(): string
    {
        return 'List the Telegram bot\'s per-chat AI settings — each chat\'s chat_id, title, type, '
            .'whether the assistant is enabled and whether it has an active conversation '
            .'(عرض إعدادات الذكاء الاصطناعي لكل محادثة تيليجرام مع معرّف المحادثة وعنوانها ونوعها وحالة التفعيل ووجود محادثة نشطة). '
            .'Use the returned chat_id values for the other telegram actions. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $chats = TelegramChatSetting::query()
            ->latest('updated_at')
            ->latest('id')
            ->get();

        if ($chats->isEmpty()) {
            return ActionResult::text('لا توجد محادثات تيليجرام مسجّلة بعد.');
        }

        $lines = $chats->map(fn (TelegramChatSetting $chat): string => sprintf(
            '- chat_id=%s | %s | %s | الذكاء: %s | محادثة نشطة: %s',
            (string) $chat->chat_id,
            $chat->title ?? '—',
            $chat->type ?? '—',
            $chat->ai_enabled ? 'مُفعّل' : 'مُعطّل',
            filled($chat->conversation_id) ? 'نعم' : 'لا',
        ));

        return ActionResult::text(
            "محادثات تيليجرام (chat_id | العنوان | النوع | حالة الذكاء | محادثة نشطة):\n"
            .$lines->implode("\n"),
        );
    }
}
