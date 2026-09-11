<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message;

class InfoHandler extends BaseHandler
{
    public function handle(Message $message): void
    {
        if (! $this->matches($message, '/^\/info$/u')) {
            return;
        }

        $this->getChatInfo($message);
    }

    protected function getChatInfo(Message $message): void
    {
        try {
            $chat = $message->getChat();
            $chatId = $chat->getId();
            $chatType = $chat->getType();
            $chatTitle = $chat->getTitle() ?? 'Private Chat';
            $chatUsername = $chat->getUsername();
            $chatDescription = $chat->getDescription();
            $chatMembersCount = $chat->getMembersCount();
            $chatInviteLink = $chat->getInviteLink();

            $user = $message->getFrom();
            $userId = $user->getId();
            $userFirstName = $user->getFirstName() ?? '';
            $userLastName = $user->getLastName();
            $userUsername = $user->getUsername();

            // Helper function to escape HTML entities
            $escape = fn ($text) => $this->escapeHtml($text ?? '');

            // Build response based on chat type
            $response = "📊 <b>معلومات الدردشة</b>\n\n";

            if ($chatType === 'private') {
                $response .= "💬 <b>نوع الدردشة:</b> محادثة خاصة\n";
                $response .= '🆔 <b>معرف المحادثة:</b> <code>'.$escape((string) $chatId)."</code>\n\n";
                $response .= "👤 <b>المستخدم:</b>\n";
                $response .= '   • الاسم: '.$escape($userFirstName);
                if ($userLastName) {
                    $response .= ' '.$escape($userLastName);
                }
                $response .= "\n";
                if ($userUsername) {
                    $response .= '   • المعرف: @'.$escape($userUsername)."\n";
                }
                $response .= '   • المعرف الرقمي: <code>'.$escape((string) $userId)."</code>\n";
            } elseif (in_array($chatType, ['group', 'supergroup'])) {
                $response .= '👥 <b>نوع الدردشة:</b> '.($chatType === 'supergroup' ? 'مجموعة خارقة' : 'مجموعة')."\n";
                $response .= '📝 <b>اسم المجموعة:</b> '.$escape($chatTitle)."\n";
                $response .= '🆔 <b>معرف المجموعة:</b> <code>'.$escape((string) $chatId)."</code>\n";
                if ($chatUsername) {
                    $response .= '🔗 <b>المعرف:</b> @'.$escape($chatUsername)."\n";
                }
                if ($chatDescription) {
                    $response .= '📄 <b>الوصف:</b> '.$escape($chatDescription)."\n";
                }
                if ($chatMembersCount) {
                    $response .= '👥 <b>عدد الأعضاء:</b> '.$escape((string) $chatMembersCount)."\n";
                }
                if ($chatInviteLink) {
                    $response .= '🔗 <b>رابط الدعوة:</b> '.$escape($chatInviteLink)."\n";
                }
                $response .= "\n";
                $response .= "👤 <b>المستخدم الحالي:</b>\n";
                $response .= '   • الاسم: '.$escape($userFirstName);
                if ($userLastName) {
                    $response .= ' '.$escape($userLastName);
                }
                $response .= "\n";
                if ($userUsername) {
                    $response .= '   • المعرف: @'.$escape($userUsername)."\n";
                }
                $response .= '   • المعرف الرقمي: <code>'.$escape((string) $userId)."</code>\n";
                $response .= "\n";
                $response .= '💡 <b>ملاحظة:</b> يمكنك استخدام معرف المجموعة مع الأمر <code>/pforward</code>';
            } elseif ($chatType === 'channel') {
                $response .= "📢 <b>نوع الدردشة:</b> قناة\n";
                $response .= '📝 <b>اسم القناة:</b> '.$escape($chatTitle)."\n";
                $response .= '🆔 <b>معرف القناة:</b> <code>'.$escape((string) $chatId)."</code>\n";
                if ($chatUsername) {
                    $response .= '🔗 <b>المعرف:</b> @'.$escape($chatUsername)."\n";
                }
                if ($chatDescription) {
                    $response .= '📄 <b>الوصف:</b> '.$escape($chatDescription)."\n";
                }
                if ($chatMembersCount) {
                    $response .= '👥 <b>عدد المشتركين:</b> '.$escape((string) $chatMembersCount)."\n";
                }
                $response .= "\n";
                $response .= "👤 <b>المستخدم الحالي:</b>\n";
                $response .= '   • الاسم: '.$escape($userFirstName);
                if ($userLastName) {
                    $response .= ' '.$escape($userLastName);
                }
                $response .= "\n";
                if ($userUsername) {
                    $response .= '   • المعرف: @'.$escape($userUsername)."\n";
                }
                $response .= '   • المعرف الرقمي: <code>'.$escape((string) $userId)."</code>\n";
                $response .= "\n";
                $response .= '💡 <b>ملاحظة:</b> يمكنك استخدام معرف القناة مع الأمر <code>/pforward</code>';
            } else {
                $response .= '🆔 <b>معرف الدردشة:</b> <code>'.$escape((string) $chatId)."</code>\n";
                $response .= '📝 <b>النوع:</b> '.$escape($chatType)."\n";
            }

            $this->replyEphemeralInGroup($message, $response, 'HTML', 60);
        } catch (TelegramSDKException $e) {
            $this->replyEphemeralInGroup($message, '❌ حدث خطأ في الحصول على معلومات الدردشة: '.$e->getMessage());
        } catch (\Exception $e) {
            $this->replyEphemeralInGroup($message, '❌ حدث خطأ غير متوقع: '.$e->getMessage());
        }
    }
}
