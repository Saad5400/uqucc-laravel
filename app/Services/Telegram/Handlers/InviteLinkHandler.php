<?php

namespace App\Services\Telegram\Handlers;

use App\Services\Telegram\InviteTracker;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message;

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
        $this->trackCommand($message, 'رابط');

        $userId = $message->getFrom()->getId();
        $chatId = $message->getChat()->getId();
        $chatType = $message->getChat()->getType();

        // Check if this is a group chat
        if (! in_array($chatType, ['group', 'supergroup'])) {
            $this->replyAndDelete($message, 'هذا الأمر يعمل فقط في المجموعات');

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

            if (! $canInvite) {
                $this->replyAndDelete($message, 'ليس لديك صلاحية لاستخدام هذا الأمر. يجب أن تكون مديراً مع صلاحية دعوة المستخدمين');

                return;
            }

            // Get user info
            $user = $message->getFrom();
            $username = $user->getUsername() ?? $user->getFirstName() ?? 'المستخدم';
            $chatTitle = $message->getChat()->getTitle() ?? 'المجموعة';

            // Name the link after its requester. Telegram shows the name in the
            // group's own invite-link admin view, so attribution survives even
            // outside our database — the field caps at 32 characters.
            $linkName = mb_substr('دعوة '.$username, 0, 32);

            // Create a one-time invite link
            $inviteLink = $this->telegram->createChatInviteLink([
                'chat_id' => $chatId,
                'name' => $linkName,
                'member_limit' => 1, // Only one user can use this link
                'creates_join_request' => false, // Direct join without approval
            ]);

            $linkUrl = $inviteLink->getInviteLink();

            app(InviteTracker::class)->recordLink(
                chatId: $chatId,
                chatTitle: $message->getChat()->getTitle(),
                inviteLink: $linkUrl,
                creator: [
                    'id' => $userId,
                    'username' => $user->getUsername(),
                    'first_name' => $user->getFirstName(),
                    'last_name' => $user->getLastName(),
                ],
                linkName: $linkName,
                memberLimit: 1,
            );

            // Send the link privately to the user
            try {
                $this->telegram->sendMessage([
                    'chat_id' => $userId,
                    'text' => "رابط دعوة خاص لمجموعة '{$chatTitle}':\n\n{$linkUrl}\n\n⚠️ هذا الرابط يعمل لشخص واحد فقط وسينتهي بعد الاستخدام",
                ]);

                // Confirm in group that link was sent
                $displayUsername = $user->getUsername() ? '@'.$user->getUsername() : $username;
                $confirmationMessage = $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "✅ تم إرسال رابط دعوة خاص إلى {$displayUsername} في الرسائل الخاصة",
                    'reply_to_message_id' => $message->getMessageId(),
                ]);

                // Delete both the user message and confirmation message after 5 seconds
                $this->deleteMessagesAfterDelay($message, $confirmationMessage);

            } catch (TelegramSDKException $e) {
                $errorMsg = strtolower($e->getMessage());
                if (strpos($errorMsg, 'forbidden') !== false || strpos($errorMsg, 'blocked') !== false) {
                    $this->replyAndDelete($message, 'لا يمكنني إرسال رسالة خاصة لك. تأكد من أنك بدأت محادثة مع البوت أولاً بإرسال /start');
                } else {
                    $this->replyAndDelete($message, 'حدث خطأ في إرسال الرابط: '.$e->getMessage());
                }
            }

        } catch (TelegramSDKException $e) {
            $errorMsg = strtolower($e->getMessage());
            if (strpos($errorMsg, 'not enough rights') !== false || strpos($errorMsg, 'administrator') !== false) {
                $this->replyAndDelete($message, 'البوت يحتاج صلاحيات إدارية لإنشاء روابط الدعوة');
            } else {
                $this->replyAndDelete($message, 'حدث خطأ في التحقق من الصلاحيات: '.$e->getMessage());
            }
        } catch (\Exception $e) {
            $this->replyAndDelete($message, 'حدث خطأ غير متوقع: '.$e->getMessage());
        }
    }
}
