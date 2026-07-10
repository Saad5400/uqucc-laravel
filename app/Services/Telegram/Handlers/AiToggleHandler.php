<?php

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramChatSetting;
use App\Settings\AiSettings;
use Telegram\Bot\Objects\Message;

/**
 * The per-chat AI activation commands: /ai_on, /ai_off, /ai_new. Activation
 * is per chat and OFF by default — in groups only Telegram-side admins of the
 * chat (creator/administrator via getChatMember) may toggle; in private chats
 * the user controls their own chat.
 */
class AiToggleHandler extends BaseHandler
{
    public function handle(Message $message): void
    {
        $text = $message->getText();
        $content = is_string($text) ? trim($text) : '';

        if (! preg_match('/^\/(ai_on|ai_off|ai_new)(?:@\w+)?$/u', $content, $matches)) {
            return;
        }

        $command = $matches[1];
        $chatType = (string) $message->getChat()->getType();

        if (! in_array($chatType, ['private', 'group', 'supergroup'], true)) {
            return;
        }

        $this->trackCommand($message, '/'.$command);

        if (! $this->userMayToggle($message, $chatType)) {
            $this->reply($message, 'هذا الأمر متاح لمشرفي المجموعة فقط. 🔒');

            return;
        }

        match ($command) {
            'ai_on' => $this->enable($message),
            'ai_off' => $this->disable($message),
            'ai_new' => $this->resetConversation($message),
        };
    }

    /**
     * In private chats the user controls their own chat; in groups only a
     * Telegram-side creator/administrator of that chat may toggle.
     */
    protected function userMayToggle(Message $message, string $chatType): bool
    {
        if ($chatType === 'private') {
            return true;
        }

        try {
            $member = $this->telegram->getChatMember([
                'chat_id' => $message->getChat()->getId(),
                'user_id' => $message->getFrom()->getId(),
            ]);

            return in_array($member->status, ['creator', 'administrator'], true);
        } catch (\Exception) {
            return false;
        }
    }

    protected function enable(Message $message): void
    {
        TelegramChatSetting::query()->updateOrCreate(
            ['chat_id' => $message->getChat()->getId()],
            [
                'ai_enabled' => true,
                'title' => $this->chatTitle($message),
                'type' => $message->getChat()->getType(),
                'enabled_by' => (string) $message->getFrom()->getId(),
            ],
        );

        $reply = "تم تفعيل المساعد الذكي في هذه المحادثة ✅\n"
            .'لسؤالي ابدأ رسالتك بكلمة «سيك» — مثال: سيك كم مكافأة الامتياز؟'
            ."\nويمكنك أيضاً الرد على إحدى رسائلي أو ذكري (منشن) للمتابعة."
            ."\nلإيقافه: /ai_off — لبدء محادثة جديدة: /ai_new";

        if (! app(AiSettings::class)->isFeatureEnabled('telegram')) {
            $reply .= "\n\nملاحظة: ميزة الذكاء الاصطناعي موقوفة حالياً من إدارة الموقع، وستعمل هنا فور تشغيلها.";
        }

        $this->reply($message, $reply);
    }

    protected function disable(Message $message): void
    {
        TelegramChatSetting::query()->updateOrCreate(
            ['chat_id' => $message->getChat()->getId()],
            [
                'ai_enabled' => false,
                'title' => $this->chatTitle($message),
                'type' => $message->getChat()->getType(),
            ],
        );

        $this->reply($message, "تم إيقاف المساعد الذكي في هذه المحادثة. 🔕\nلإعادة تفعيله: /ai_on");
    }

    protected function resetConversation(Message $message): void
    {
        $settings = TelegramChatSetting::forChat($message->getChat()->getId());

        if ($settings === null || ! $settings->ai_enabled) {
            $this->reply($message, 'المساعد الذكي غير مفعل في هذه المحادثة. فعّله أولاً بالأمر /ai_on');

            return;
        }

        $settings->update(['conversation_id' => null]);

        $this->reply($message, 'بدأنا محادثة جديدة — نسيت ما سبق. 🌱');
    }

    /**
     * Snapshot name for admin visibility: the group title, or the person's
     * name/username for private chats.
     */
    protected function chatTitle(Message $message): ?string
    {
        $chat = $message->getChat();

        $title = $chat->getTitle()
            ?? trim(implode(' ', array_filter([$chat->getFirstName(), $chat->getLastName()])));

        if ($title === '' || $title === null) {
            $title = $chat->getUsername();
        }

        return $title !== null && $title !== '' ? (string) $title : null;
    }

    protected function isGroupChat(Message $message): bool
    {
        return in_array($message->getChat()->getType(), ['group', 'supergroup'], true);
    }
}
