<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message;

class PrivateForwardHandler extends BaseHandler
{
    public function handle(Message $message): void
    {
        $text = $message->getText();
        $content = is_string($text) ? trim($text) : '';

        // Check if message matches /pforward command with optional channel ID
        if (! preg_match('/^\/pforward(?:\s+(-?\d+))?$/u', $content, $matches)) {
            return;
        }

        $targetChannelId = isset($matches[1]) ? (int) $matches[1] : null;
        $this->privateForward($message, $targetChannelId);
    }

    protected function privateForward(Message $message, ?int $targetChannelId): void
    {
        $userId = $message->getFrom()->getId();
        $replyToMessage = $message->getReplyToMessage();

        // Check if user is replying to a message
        if (! $replyToMessage) {
            $this->replyEphemeralInGroup(
                $message,
                "❌ يجب أن ترد على رسالة لإعادة توجيهها.\n\nالاستخدام: رد على رسالة بـ /pforward <معرف_القناة>"
            );

            return;
        }

        // Check if channel ID is provided
        if ($targetChannelId === null) {
            $this->replyEphemeralInGroup(
                $message,
                "❌ يجب تحديد معرف القناة.\n\nالاستخدام: /pforward <معرف_القناة>\n\nمثال: /pforward -1001234567890"
            );

            return;
        }

        try {
            // Check if user is admin in target channel
            $userMember = $this->telegram->getChatMember([
                'chat_id' => $targetChannelId,
                'user_id' => $userId,
            ]);

            $status = $userMember->status;
            $isAdmin = in_array($status, ['creator', 'administrator']);

            if (! $isAdmin) {
                $this->replyEphemeralInGroup(
                    $message,
                    '❌ ليس لديك صلاحيات المسؤول في القناة المحددة.'
                );

                return;
            }

            // Check if bot is a member of the target channel
            $botId = $this->telegram->getMe()->getId();
            $botMember = $this->telegram->getChatMember([
                'chat_id' => $targetChannelId,
                'user_id' => $botId,
            ]);

            $botStatus = $botMember->status;
            if (! in_array($botStatus, ['member', 'administrator', 'creator'])) {
                $this->replyEphemeralInGroup(
                    $message,
                    "❌ البوت ليس عضواً في القناة المحددة.\n\nحالة البوت: {$botStatus}"
                );

                return;
            }

            // Forward the message with content protection
            $forwardedMessage = $this->telegram->copyMessage([
                'chat_id' => $targetChannelId,
                'from_chat_id' => $replyToMessage->getChat()->getId(),
                'message_id' => $replyToMessage->getMessageId(),
                'protect_content' => true,
            ]);

            // Get channel info for confirmation
            try {
                $channel = $this->telegram->getChat(['chat_id' => $targetChannelId]);
                $channelName = $channel->getTitle() ?? (string) $targetChannelId;
            } catch (\Exception $e) {
                $channelName = (string) $targetChannelId;
            }

            // Send confirmation
            $this->replyEphemeralInGroup(
                $message,
                "✅ تم إعادة توجيه الرسالة بشكل خاص إلى: {$channelName}\n"
                .'🔒 الرسالة محمية من النسخ وإعادة التوجيه.'
            );

        } catch (TelegramSDKException $e) {
            $errorMsg = strtolower($e->getMessage());

            if (strpos($errorMsg, 'chat not found') !== false || strpos($errorMsg, 'not found') !== false) {
                $this->replyEphemeralInGroup(
                    $message,
                    "❌ القناة المحددة غير موجودة أو المعرف غير صحيح.\n\nخطأ: ".$e->getMessage()
                );
            } elseif (strpos($errorMsg, 'user not found') !== false || strpos($errorMsg, 'participant') !== false) {
                $this->replyEphemeralInGroup(
                    $message,
                    "❌ أنت لست عضواً في القناة المحددة.\n\nخطأ: ".$e->getMessage()
                );
            } elseif (strpos($errorMsg, 'forbidden') !== false) {
                $this->replyEphemeralInGroup(
                    $message,
                    "❌ البوت محظور أو لا يملك صلاحيات الإرسال في القناة.\n\nخطأ: ".$e->getMessage()
                );
            } elseif (strpos($errorMsg, 'have no rights') !== false || strpos($errorMsg, 'not enough rights') !== false) {
                $this->replyEphemeralInGroup(
                    $message,
                    "❌ البوت لا يملك صلاحيات الإرسال في القناة المحددة.\n\nخطأ: ".$e->getMessage()
                );
            } else {
                $this->replyEphemeralInGroup(
                    $message,
                    '❌ فشل إعادة توجيه الرسالة: '.$e->getMessage()
                );
            }
        } catch (\Exception $e) {
            $this->replyEphemeralInGroup(
                $message,
                '❌ خطأ غير متوقع: '.$e->getMessage()
            );
        }
    }
}
