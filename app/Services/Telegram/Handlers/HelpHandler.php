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
• بحث [جزء من الاسم]
• بعض الصفحات بدون "دليل"
• بحث ذكي (جزء من الاسم)
• الفهرس - جميع الصفحات

<b>🌐 البحث الخارجي:</b>
• قوقل [استعلام] - بحث Google
• قيم [اسم المقرر/الدكتور] - بحث قيم

<b>🤖 المساعد الذكي:</b>
• /ai_on - تفعيل المساعد في المحادثة
• /ai_off - إيقاف المساعد
• /ai_new - بدء محادثة جديدة
• اسال سيك [سؤالك]
• في المجموعات: اذكر البوت أو رد عليه

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

<a href="https://www.youtube.com/watch?v=eoOFEHhWqPA">🎬 فيديو شرح البوت (دقيقتين)</a>
<a href="https://www.youtube.com/watch?v=ifZBbUwnIf0">🎬 فيديو شرح الموقع (6 دقائق)</a>

<b>🔐 الحساب:</b>
• تسجيل دخول / تسجيل خروج

<b>📝 إدارة الصفحات:</b>
• أضف صفحة - إنشاء صفحة جديدة
• حذف صفحة - حذف صفحة
• تعديل [اسم] - رابط التعديل
• الصفحات الذكية - عرض الذكية
• إلغاء - إلغاء العملية

<b>الأزرار التفاعلية:</b>
• (نص الزر|رابط) - زر واحد
• [صف:2-1] - توزيع الأزرار
  ○ 1 = زر كامل العرض
  ○ 2 = زران في صف
  ○ 3 = ثلاثة أزرار

مثال:
[صف:2-1]
(زيارة الموقع|https://example.com)
(تواصل معنا|https://t.me/channel)
(المزيد|https://example.com/more)

<b>التواريخ الذكية:</b>
• ميلادي: {2024-12-25}
• هجري: &lt;1446-06-15&gt;
• متكرر: {*-*-27} (كل 27)
• التوقيت: {2024-12-25 9:00 ص}
• قاعدة الأيام: {*-*-27|جمعة:-1}
  يحول الجمعة للخميس

يعرض: اليوم [هجري] [ميلادي] [العد التنازلي]

<b>💡 ملاحظات:</b>
• التعديل يتطلب تسجيل دخول
• الأوامر تعمل في المجموعات والخاص
• التغييرات فورية في البوت
HELP;
    }
}
