<?php

namespace App\Ai\Admin\Actions\Telegram;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\TelegramChatSetting;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Forget the assistant's current conversation for a Telegram chat so it starts
 * fresh, mirroring {@see \App\Http\Controllers\Manage\TelegramChatSettingController::resetConversation()}.
 * Identify the chat by its Telegram chat_id (from list_telegram_chats).
 */
class ResetTelegramChatAction extends AdminAction
{
    public function name(): string
    {
        return 'reset_telegram_chat';
    }

    public function category(): string
    {
        return 'telegram';
    }

    public function description(): string
    {
        return 'Reset a Telegram chat\'s assistant conversation so it starts fresh next time '
            .'(إعادة تعيين محادثة مساعد الذكاء الاصطناعي لمحادثة تيليجرام لتبدأ من جديد). '
            .'Provide chat_id (from list_telegram_chats). This clears the current conversation memory only; it does not disable the assistant.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chat_id' => $schema->string()
                ->description('The Telegram chat_id of the chat to reset, from list_telegram_chats.')
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

        return 'إعادة تعيين محادثة مساعد الذكاء الاصطناعي لمحادثة تيليجرام '.$label.'.';
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

        $chat->update(['conversation_id' => null]);

        $label = filled($chat->title) ? '«'.$chat->title.'»' : 'رقم '.$chat->chat_id;

        return ActionResult::text('تمت إعادة تعيين محادثة مساعد الذكاء الاصطناعي لمحادثة تيليجرام '.$label.'.');
    }
}
