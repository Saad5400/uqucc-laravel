<?php

namespace App\Ai\Admin\Actions\Telegram;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\TelegramChatSetting;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Delete a Telegram chat's settings row, mirroring
 * {@see \App\Http\Controllers\Manage\TelegramChatSettingController::destroy()}.
 * The assistant falls back to inactive for that chat until it re-enables itself.
 * Identify the chat by its Telegram chat_id (from list_telegram_chats).
 */
class DeleteTelegramChatAction extends AdminAction
{
    public function name(): string
    {
        return 'delete_telegram_chat';
    }

    public function category(): string
    {
        return 'telegram';
    }

    public function description(): string
    {
        return 'Delete a Telegram chat\'s AI settings row '
            .'(حذف إعدادات الذكاء الاصطناعي لمحادثة تيليجرام). '
            .'Provide chat_id (from list_telegram_chats). The assistant becomes inactive for that chat until it is re-enabled from within the chat.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chat_id' => $schema->string()
                ->description('The Telegram chat_id of the chat whose settings to delete, from list_telegram_chats.')
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $chat = TelegramChatSetting::forChat((string) ($input['chat_id'] ?? ''));

        if ($chat === null) {
            throw new AdminActionException('لا توجد محادثة تيليجرام بهذا المعرّف. استخدم list_telegram_chats للتأكد.');
        }

        return [
            'chat_id' => (string) $chat->chat_id,
            'chat_title' => $chat->title,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        $label = filled($normalized['chat_title']) ? '«'.$normalized['chat_title'].'»' : 'رقم '.$normalized['chat_id'];

        return 'حذف إعدادات الذكاء الاصطناعي لمحادثة تيليجرام '.$label.'.';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $chat = TelegramChatSetting::forChat($normalized['chat_id']);

        if ($chat === null) {
            throw new AdminActionException('المحادثة المستهدفة لم تعد موجودة.');
        }

        $label = filled($chat->title) ? '«'.$chat->title.'»' : 'رقم '.$chat->chat_id;

        $chat->delete();

        return ActionResult::text('تم حذف إعدادات الذكاء الاصطناعي لمحادثة تيليجرام '.$label.'.');
    }
}
