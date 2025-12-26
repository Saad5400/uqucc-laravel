<?php

namespace App\Services\Telegram\Handlers;

use App\Models\User;
use Telegram\Bot\Objects\Message;

class HelpHandler extends BaseHandler
{
    public function handle(Message $message): void
    {
        $text = $message->getText();

        // Check for /help or help command
        if (! in_array($text, ['/help'])) {
            return;
        }

        $userId = $message->getFrom()->getId();
        $user = User::findByTelegramId((string) $userId);

        // Build help message based on user permissions
        $helpMessage = $this->buildHelpMessage($user);

        $this->replyHtml($message, $helpMessage);
    }

    /**
     * Build help message based on user permissions.
     */
    protected function buildHelpMessage(?User $user): string
    {
        $sections = [];

        // Basic user guide
        $sections[] = $this->getBasicUserGuide();

        // Add management guide if user has permissions
        if ($user && $user->canManagePagesViaTelegram()) {
            $sections[] = $this->getManagementGuide();
        }

        return implode("\n\n".str_repeat('─', 30)."\n\n", $sections);
    }

    /**
     * Get basic user help guide.
     */
    protected function getBasicUserGuide(): string
    {
        return <<<'HELP'
<b>📚 دليل استخدام البوت</b>

<b>🔍 البحث:</b>
• دليل [اسم الصفحة]
• بعض الصفحات بدون "دليل"
• بحث ذكي (جزء من الاسم)
• الفهرس - جميع الصفحات

<b>🤖 الذكاء الاصطناعي:</b>
• اسال سيك [سؤالك]

<b>💻 تشغيل الأكواد:</b>
• شغل بايثون [كود]
• شغل جافا [كود]

<b>📱 أوامر أخرى:</b>
• /info - معلومات البوت
• /help - هذه المساعدة
• رابط - دعوة (في المجموعات)
HELP;
    }

    /**
     * Get management guide for authorized users.
     */
    protected function getManagementGuide(): string
    {
        return <<<'HELP'
<b>⚙️ دليل الإدارة</b>
<i>(متاح لك كمدير محتوى)</i>

<b>🔐 الحساب:</b>
• تسجيل دخول / تسجيل خروج

<b>📝 إدارة الصفحات:</b>
• أضف صفحة - إنشاء صفحة جديدة
• حذف صفحة - حذف صفحة
• تعديل [اسم] - رابط التعديل
• الصفحات الذكية - عرض الذكية
• إلغاء - إلغاء العملية

<b>💡 ملاحظات:</b>
• التعديل يتطلب تسجيل دخول
• الأوامر تعمل في المجموعات والخاص
• التغييرات فورية في البوت
HELP;
    }
}
