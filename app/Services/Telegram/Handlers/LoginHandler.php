<?php

namespace App\Services\Telegram\Handlers;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Telegram\Bot\Objects\Message;

class LoginHandler extends BaseHandler
{
    private const STATE_KEY_PREFIX = 'telegram_login_state_';

    private const STATE_TTL = 300; // 5 minutes

    public function handle(Message $message): void
    {
        $chatId = $message->getChat()->getId();
        $userId = $message->getFrom()->getId();
        $text = trim($message->getText() ?? '');

        // Only handle private messages for login
        if ($message->getChat()->getType() !== 'private') {
            return;
        }

        // Check if user is in a login state
        $state = $this->getState($userId);

        // Check for cancel command - only cancel if there's an active login state
        if ($text === 'إلغاء' || $text === 'الغاء') {
            if ($state) {
                $this->clearState($userId);
                $this->reply($message, 'تم إلغاء عملية تسجيل الدخول.');
            }
            // Don't respond if no active state - let other handlers handle it

            return;
        }

        if ($state) {
            $this->handleLoginState($message, $state);

            return;
        }

        // Check for login command (with and without hamza)
        if (in_array($text, ['تسجيل دخول', 'تسجيل الدخول'])) {
            // Check if already linked
            $existingUser = User::findByTelegramId((string) $userId);
            if ($existingUser) {
                $this->reply($message, "أنت مسجل دخول بالفعل كـ: {$existingUser->name}\n\nللخروج وتسجيل حساب آخر، أرسل: تسجيل خروج");

                return;
            }

            $this->setState($userId, [
                'step' => 'awaiting_email',
            ]);

            $this->reply($message, "مرحباً! لتسجيل الدخول، أرسل بريدك الإلكتروني:\n\n(أرسل 'إلغاء' للإلغاء)");

            return;
        }

        // Handle logout
        if ($text === 'تسجيل خروج') {
            $user = User::findByTelegramId((string) $userId);
            if ($user) {
                $user->update(['telegram_id' => null]);
                $this->reply($message, 'تم تسجيل خروجك بنجاح.');
            } else {
                $this->reply($message, 'لست مسجل دخول.');
            }

            return;
        }
    }

    protected function handleLoginState(Message $message, array $state): void
    {
        $userId = $message->getFrom()->getId();
        $text = trim($message->getText() ?? '');

        switch ($state['step']) {
            case 'awaiting_email':
                $this->handleEmailInput($message, $text);
                break;

            case 'awaiting_password':
                $this->handlePasswordInput($message, $text, $state['email']);
                break;
        }
    }

    protected function handleEmailInput(Message $message, string $email): void
    {
        $userId = $message->getFrom()->getId();

        // Validate email format
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->reply($message, "البريد الإلكتروني غير صالح. أرسل بريد إلكتروني صحيح:\n\n(أرسل 'إلغاء' للإلغاء)");

            return;
        }

        // Check if user exists
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->reply($message, "لم يتم العثور على حساب بهذا البريد الإلكتروني.\n\nأرسل بريد إلكتروني آخر أو 'إلغاء' للإلغاء");

            return;
        }

        // Save email and ask for password
        $this->setState($userId, [
            'step' => 'awaiting_password',
            'email' => $email,
        ]);

        $this->reply($message, "تم العثور على الحساب. أرسل كلمة المرور:\n\n(أرسل 'إلغاء' للإلغاء)");
    }

    protected function handlePasswordInput(Message $message, string $password, string $email): void
    {
        $userId = $message->getFrom()->getId();

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->reply($message, "كلمة المرور غير صحيحة.\n\nأعد إرسال كلمة المرور أو أرسل 'إلغاء' للإلغاء");

            return;
        }

        // Check if telegram_id is already linked to another account
        $existingUser = User::findByTelegramId((string) $userId);
        if ($existingUser && $existingUser->id !== $user->id) {
            $existingUser->update(['telegram_id' => null]);
        }

        // Link telegram account
        $user->update(['telegram_id' => (string) $userId]);

        $this->clearState($userId);

        $permissions = [];
        if ($user->canManagePagesViaTelegram()) {
            $permissions[] = 'إدارة الصفحات';
        }

        $permissionsText = empty($permissions) ? '' : "\n\nصلاحياتك: ".implode('، ', $permissions);

        $this->reply($message, "تم تسجيل الدخول بنجاح!\n\nمرحباً {$user->name}{$permissionsText}");
    }

    protected function getState(int $userId): ?array
    {
        return Cache::get(self::STATE_KEY_PREFIX.$userId);
    }

    protected function setState(int $userId, array $state): void
    {
        Cache::put(self::STATE_KEY_PREFIX.$userId, $state, self::STATE_TTL);
    }

    protected function clearState(int $userId): void
    {
        Cache::forget(self::STATE_KEY_PREFIX.$userId);
    }
}
