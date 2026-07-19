<?php

namespace App\Ai\Admin\Actions\Telegram;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\TelegramChatSetting;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Enable or disable the bot's AI assistant for a single Telegram chat, mirroring
 * {@see \App\Http\Controllers\Manage\TelegramChatSettingController::update()}.
 * Identify the chat by its Telegram chat_id (from list_telegram_chats).
 */
class SetTelegramChatAiAction extends AdminAction
{
    public function name(): string
    {
        return 'set_telegram_chat_ai';
    }

    public function category(): string
    {
        return 'telegram';
    }

    public function description(): string
    {
        return 'Enable or disable the AI assistant for a Telegram chat '
            .'(تفعيل أو تعطيل مساعد الذكاء الاصطناعي لمحادثة تيليجرام). '
            .'Provide chat_id (from list_telegram_chats) and enabled (true to turn the assistant on, false to turn it off).';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chat_id' => $schema->string()
                ->description('The Telegram chat_id of the chat to change, from list_telegram_chats.')
                ->required(),
            'enabled' => $schema->boolean()
                ->description('True to enable the assistant for this chat, false to disable it.')
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
            'enabled' => (bool) ($input['enabled'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        $label = filled($normalized['chat_title']) ? '«'.$normalized['chat_title'].'»' : 'رقم '.$normalized['chat_id'];

        return ($normalized['enabled'] ? 'تفعيل' : 'تعطيل').' مساعد الذكاء الاصطناعي لمحادثة تيليجرام '.$label.'.';
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

        $chat->update(['ai_enabled' => $normalized['enabled']]);

        $label = filled($chat->title) ? '«'.$chat->title.'»' : 'رقم '.$chat->chat_id;

        return ActionResult::text(
            'تم '.($normalized['enabled'] ? 'تفعيل' : 'تعطيل').' مساعد الذكاء الاصطناعي لمحادثة تيليجرام '.$label.'.',
        );
    }
}
