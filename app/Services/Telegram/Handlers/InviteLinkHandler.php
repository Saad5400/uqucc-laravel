<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Objects\Message;
use Telegram\Bot\Exceptions\TelegramSDKException;

class InviteLinkHandler extends BaseHandler
{
    public function handle(Message $message): void
    {
        // Check if message is exactly "رابط" (not a command)
        $text = $message->getText();
        $content = is_string($text) ? trim($text) : '';

        if ($content !== 'رابط') {
            return;
        }

        $this->createInviteLink($message);
    }

    protected function createInviteLink(Message $message): void
    {
        $userId = $message->getFrom()->getId();
        $chatId = $message->getChat()->getId();
        $chatType = $message->getChat()->getType();

        // Check if this is a group chat
        if (!in_array($chatType, ['group', 'supergroup'])) {
            $this->reply(
                $message,
                "هذا الأمر يعمل فقط في المجموعات"
            );
            return;
        }

        try {
            // Get the user's status in the chat
            $chatMember = $this->telegram->getChatMember([
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);

            $status = $chatMember->status;

            // Check if user is an admin with invite permissions or the chat owner
            $canInvite = false;

            if ($status === 'creator') {
                // Chat owner can always invite
                $canInvite = true;
            } elseif ($status === 'administrator') {
                // Check if admin has permission to invite users
                // Access the property using magic property access (converts to snake_case internally)
                $canInvite = $chatMember->canInviteUsers ?? false;
            }

            if (!$canInvite) {
                $this->reply(
                    $message,
                    "ليس لديك صلاحية لاستخدام هذا الأمر. يجب أن تكون مديراً مع صلاحية دعوة المستخدمين"
                );
                return;
            }

            // Create a one-time invite link
            $inviteLink = $this->telegram->createChatInviteLink([
                'chat_id' => $chatId,
                'member_limit' => 1, // Only one user can use this link
                'creates_join_request' => false, // Direct join without approval
            ]);

            $linkUrl = $inviteLink->getInviteLink();

            // Get user info
            $user = $message->getFrom();
            $username = $user->getUsername() ?? $user->getFirstName() ?? "المستخدم";
            $chatTitle = $message->getChat()->getTitle() ?? 'المجموعة';

            // Send the link privately to the user
            try {
                $this->telegram->sendMessage([
                    'chat_id' => $userId,
                    'text' => "رابط دعوة خاص لمجموعة '{$chatTitle}':\n\n{$linkUrl}\n\n⚠️ هذا الرابط يعمل لشخص واحد فقط وسينتهي بعد الاستخدام",
                ]);

                // Confirm in group that link was sent
                $displayUsername = $user->getUsername() ? '@' . $user->getUsername() : $username;
                $this->reply(
                    $message,
                    "✅ تم إرسال رابط دعوة خاص إلى {$displayUsername} في الرسائل الخاصة"
                );

            } catch (TelegramSDKException $e) {
                $errorMsg = strtolower($e->getMessage());
                if (strpos($errorMsg, 'forbidden') !== false || strpos($errorMsg, 'blocked') !== false) {
                    $this->reply(
                        $message,
                        "لا يمكنني إرسال رسالة خاصة لك. تأكد من أنك بدأت محادثة مع البوت أولاً بإرسال /start"
                    );
                } else {
                    $this->reply(
                        $message,
                        "حدث خطأ في إرسال الرابط: " . $e->getMessage()
                    );
                }
            }

        } catch (TelegramSDKException $e) {
            $errorMsg = strtolower($e->getMessage());
            if (strpos($errorMsg, 'not enough rights') !== false || strpos($errorMsg, 'administrator') !== false) {
                $this->reply(
                    $message,
                    "البوت يحتاج صلاحيات إدارية لإنشاء روابط الدعوة"
                );
            } else {
                $this->reply(
                    $message,
                    "حدث خطأ في التحقق من الصلاحيات: " . $e->getMessage()
                );
            }
        } catch (\Exception $e) {
            $this->reply(
                $message,
                "حدث خطأ غير متوقع: " . $e->getMessage()
            );
        }
    }
}

