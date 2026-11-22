<?php

namespace App\Services\Telegram\Handlers;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Telegram\Bot\Objects\Message;
use Throwable;

class LoginHandler extends BaseHandler
{
    /** Failed passwords allowed per Telegram account / per email per window. */
    protected const MAX_ATTEMPTS = 5;

    protected const LOCKOUT_SECONDS = 900;

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

        // Whether an account exists is NOT revealed here: the same prompt
        // follows any well-formed address, so the bot cannot be used to find
        // out which emails hold a /manage account.
        $this->setState($userId, [
            'step' => 'awaiting_password',
            'email' => $email,
        ]);

        $this->reply($message, "أرسل كلمة المرور:\n\n(أرسل 'إلغاء' للإلغاء)");
    }

    protected function handlePasswordInput(Message $message, string $password, string $email): void
    {
        $userId = $message->getFrom()->getId();

        // The password must not stay readable in the chat history.
        $this->deletePasswordMessage($message);

        $throttleKeys = $this->throttleKeys($userId, $email);

        if ($this->isLockedOut($throttleKeys)) {
            $this->clearState($userId);

            $minutes = (int) ceil(max(array_map(RateLimiter::availableIn(...), $throttleKeys)) / 60);

            $this->reply($message, "محاولات دخول كثيرة. حاول مرة أخرى بعد {$minutes} دقيقة.");

            return;
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            foreach ($throttleKeys as $key) {
                RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            }

            if ($this->isLockedOut($throttleKeys)) {
                $this->clearState($userId);
                $this->reply($message, 'بيانات الدخول غير صحيحة. تم إيقاف المحاولات مؤقتاً، حاول لاحقاً.');

                return;
            }

            $this->reply($message, "البريد الإلكتروني أو كلمة المرور غير صحيحة.\n\nأعد إرسال كلمة المرور أو أرسل 'إلغاء' للإلغاء");

            return;
        }

        foreach ($throttleKeys as $key) {
            RateLimiter::clear($key);
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

    /**
     * Failed passwords are counted per Telegram account AND per target email,
     * so neither one Telegram user cycling emails nor many Telegram accounts
     * hammering one email get more than MAX_ATTEMPTS guesses per window. The
     * /manage form has its own limiter (LoginRequest); without this the bot
     * was an unthrottled side door to the same passwords.
     *
     * @return list<string>
     */
    protected function throttleKeys(int $telegramUserId, string $email): array
    {
        return [
            'telegram-login:user:'.$telegramUserId,
            'telegram-login:email:'.sha1(Str::lower($email)),
        ];
    }

    /**
     * @param  list<string>  $keys
     */
    protected function isLockedOut(array $keys): bool
    {
        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                return true;
            }
        }

        return false;
    }

    protected function deletePasswordMessage(Message $message): void
    {
        try {
            $this->telegram->deleteMessage([
                'chat_id' => $message->getChat()->getId(),
                'message_id' => $message->getMessageId(),
            ]);
        } catch (Throwable $e) {
            // Best effort: the bot may lack the right to delete it.
        }
    }

    protected function getState(int $userId): ?array
    {
        $prefix = config('app-cache.keys.telegram_login_state', 'telegram_login_state_');

        return Cache::get($prefix.$userId);
    }

    protected function setState(int $userId, array $state): void
    {
        $prefix = config('app-cache.keys.telegram_login_state', 'telegram_login_state_');
        $ttl = config('app-cache.telegram.login_state_ttl', 300);
        Cache::put($prefix.$userId, $state, $ttl);
    }

    protected function clearState(int $userId): void
    {
        $prefix = config('app-cache.keys.telegram_login_state', 'telegram_login_state_');
        Cache::forget($prefix.$userId);
    }
}
