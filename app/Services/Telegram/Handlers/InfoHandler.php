<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Objects\Message;
use Telegram\Bot\Exceptions\TelegramSDKException;

class InfoHandler extends BaseHandler
{
    public function handle(Message $message): void
    {
        if (!$this->matches($message, '/^\/info$/u')) {
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

            // Helper function to escape markdown values
            $escape = fn($text) => $this->escapeMarkdownV2($text ?? '');

            // Build response based on chat type
            $response = "📊 *معلومات الدردشة*\n\n";

            if ($chatType === 'private') {
                $response .= "💬 *نوع الدردشة:* محادثة خاصة\n";
                $response .= "🆔 *معرف المحادثة:* `" . $escape((string)$chatId) . "`\n\n";
                $response .= "👤 *المستخدم:*\n";
                $response .= "   • الاسم: " . $escape($userFirstName);
                if ($userLastName) {
                    $response .= " " . $escape($userLastName);
                }
                $response .= "\n";
                if ($userUsername) {
                    $response .= "   • المعرف: @" . $escape($userUsername) . "\n";
                }
                $response .= "   • المعرف الرقمي: `" . $escape((string)$userId) . "`\n";
            } elseif (in_array($chatType, ['group', 'supergroup'])) {
                $response .= "👥 *نوع الدردشة:* " . ($chatType === 'supergroup' ? 'مجموعة خارقة' : 'مجموعة') . "\n";
                $response .= "📝 *اسم المجموعة:* " . $escape($chatTitle) . "\n";
                $response .= "🆔 *معرف المجموعة:* `" . $escape((string)$chatId) . "`\n";
                if ($chatUsername) {
                    $response .= "🔗 *المعرف:* @" . $escape($chatUsername) . "\n";
                }
                if ($chatDescription) {
                    $response .= "📄 *الوصف:* " . $escape($chatDescription) . "\n";
                }
                if ($chatMembersCount) {
                    $response .= "👥 *عدد الأعضاء:* " . $escape((string)$chatMembersCount) . "\n";
                }
                if ($chatInviteLink) {
                    $response .= "🔗 *رابط الدعوة:* " . $escape($chatInviteLink) . "\n";
                }
                $response .= "\n";
                $response .= "👤 *المستخدم الحالي:*\n";
                $response .= "   • الاسم: " . $escape($userFirstName);
                if ($userLastName) {
                    $response .= " " . $escape($userLastName);
                }
                $response .= "\n";
                if ($userUsername) {
                    $response .= "   • المعرف: @" . $escape($userUsername) . "\n";
                }
                $response .= "   • المعرف الرقمي: `" . $escape((string)$userId) . "`\n";
                $response .= "\n";
                $response .= "💡 *ملاحظة:* يمكنك استخدام معرف المجموعة مع الأمر `/pforward`";
            } elseif ($chatType === 'channel') {
                $response .= "📢 *نوع الدردشة:* قناة\n";
                $response .= "📝 *اسم القناة:* " . $escape($chatTitle) . "\n";
                $response .= "🆔 *معرف القناة:* `" . $escape((string)$chatId) . "`\n";
                if ($chatUsername) {
                    $response .= "🔗 *المعرف:* @" . $escape($chatUsername) . "\n";
                }
                if ($chatDescription) {
                    $response .= "📄 *الوصف:* " . $escape($chatDescription) . "\n";
                }
                if ($chatMembersCount) {
                    $response .= "👥 *عدد المشتركين:* " . $escape((string)$chatMembersCount) . "\n";
                }
                $response .= "\n";
                $response .= "👤 *المستخدم الحالي:*\n";
                $response .= "   • الاسم: " . $escape($userFirstName);
                if ($userLastName) {
                    $response .= " " . $escape($userLastName);
                }
                $response .= "\n";
                if ($userUsername) {
                    $response .= "   • المعرف: @" . $escape($userUsername) . "\n";
                }
                $response .= "   • المعرف الرقمي: `" . $escape((string)$userId) . "`\n";
                $response .= "\n";
                $response .= "💡 *ملاحظة:* يمكنك استخدام معرف القناة مع الأمر `/pforward`";
            } else {
                $response .= "🆔 *معرف الدردشة:* `" . $escape((string)$chatId) . "`\n";
                $response .= "📝 *النوع:* " . $escape($chatType) . "\n";
            }
            
            $this->replyMarkdown($message, $response);
        } catch (TelegramSDKException $e) {
            $this->reply($message, "❌ حدث خطأ في الحصول على معلومات الدردشة: " . $e->getMessage());
        } catch (\Exception $e) {
            $this->reply($message, "❌ حدث خطأ غير متوقع: " . $e->getMessage());
        }
    }
}

