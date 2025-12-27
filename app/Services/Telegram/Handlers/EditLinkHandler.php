<?php

namespace App\Services\Telegram\Handlers;

use App\Models\Page;
use App\Models\User;
use App\Services\Telegram\Traits\SearchesPages;
use Telegram\Bot\Objects\Message;

class EditLinkHandler extends BaseHandler
{
    use SearchesPages;

    public function handle(Message $message): void
    {
        $text = $message->getText();
        $content = is_string($text) ? trim($text) : '';

        if (empty($content)) {
            return;
        }

        // Check if it matches "تعديل <query>" pattern (with or without hamza)
        if (! preg_match('/^تعديل\s+(.+)$/u', $content, $matches)) {
            return;
        }

        $query = $matches[1];
        $userId = $message->getFrom()->getId();

        // Check if user is authorized
        $user = $this->getAuthorizedUser($userId);
        if (! $user) {
            $this->replyAndDelete($message, 'عذراً، ليس لديك صلاحية للوصول إلى روابط التعديل. يرجى تسجيل الدخول أولاً باستخدام: تسجيل دخول');

            return;
        }

        // Search for the page
        $page = $this->searchPage($query);

        if (! $page) {
            $this->replyAndDelete($message, 'الصفحة غير موجودة');

            return;
        }

        // Send the edit link
        $this->sendEditLink($message, $page);
    }

    /**
     * Get authorized user with edit content permission.
     */
    protected function getAuthorizedUser(int $telegramId): ?User
    {
        $user = User::findByTelegramId((string) $telegramId);

        if (! $user || ! $user->canManagePagesViaTelegram()) {
            return null;
        }

        return $user;
    }

    /**
     * Send the admin panel edit link for the page.
     */
    protected function sendEditLink(Message $message, Page $page): void
    {
        $editUrl = url('/admin/pages/'.$page->id.'/edit');

        $responseText = "رابط تعديل الصفحة: {$page->title}\n\n{$editUrl}";

        $this->replyAndDelete($message, $responseText);
    }
}
