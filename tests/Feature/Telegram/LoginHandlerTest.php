<?php

use App\Models\User;
use App\Services\Telegram\Handlers\LoginHandler;
use Illuminate\Support\Facades\RateLimiter;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

function loginMessage(string $text, int $telegramUserId = 777, int $messageId = 10): Message
{
    return new Message([
        'message_id' => $messageId,
        'from' => ['id' => $telegramUserId, 'is_bot' => false, 'first_name' => 'مشرف'],
        'chat' => ['id' => $telegramUserId, 'type' => 'private', 'first_name' => 'مشرف'],
        'text' => $text,
    ]);
}

/** Walk the bot's login dialogue up to the password prompt for $email. */
function startTelegramLogin(FakeTelegramApi $api, string $email, int $telegramUserId = 777): void
{
    (new LoginHandler($api))->handle(loginMessage('تسجيل دخول', $telegramUserId));
    (new LoginHandler($api))->handle(loginMessage($email, $telegramUserId));
}

beforeEach(function () {
    RateLimiter::clear('telegram-login:user:777');
    RateLimiter::clear('telegram-login:user:778');
    RateLimiter::clear('telegram-login:email:'.sha1('admin@example.com'));
});

it('links the telegram account on the right password and deletes the password message', function () {
    $user = User::factory()->create(['email' => 'admin@example.com', 'password' => 'correct-horse']);
    $api = new FakeTelegramApi;

    startTelegramLogin($api, 'admin@example.com');
    (new LoginHandler($api))->handle(loginMessage('correct-horse', messageId: 42));

    expect($user->fresh()->telegram_id)->toBe('777')
        ->and(collect($api->deletedMessages)->pluck('message_id')->all())->toContain(42)
        ->and(end($api->sentMessages)['text'])->toContain('تم تسجيل الدخول بنجاح');
});

it('does not reveal whether an email has an account', function () {
    User::factory()->create(['email' => 'admin@example.com']);

    $known = new FakeTelegramApi;
    startTelegramLogin($known, 'admin@example.com', 777);

    $unknown = new FakeTelegramApi;
    startTelegramLogin($unknown, 'nobody@example.com', 778);

    expect(end($known->sentMessages)['text'])->toBe(end($unknown->sentMessages)['text']);
});

it('locks a telegram account out after five wrong passwords, even with the right one', function () {
    $user = User::factory()->create(['email' => 'admin@example.com', 'password' => 'correct-horse']);
    $api = new FakeTelegramApi;

    startTelegramLogin($api, 'admin@example.com');

    foreach (range(1, 5) as $attempt) {
        (new LoginHandler($api))->handle(loginMessage('wrong-'.$attempt));
    }

    expect(end($api->sentMessages)['text'])->toContain('تم إيقاف المحاولات مؤقتاً');

    // The dialogue was closed; a fresh one is refused before the password is checked.
    startTelegramLogin($api, 'admin@example.com');
    (new LoginHandler($api))->handle(loginMessage('correct-horse'));

    expect($user->fresh()->telegram_id)->toBeNull()
        ->and(end($api->sentMessages)['text'])->toContain('محاولات دخول كثيرة');
});

it('throttles one email across different telegram accounts', function () {
    $user = User::factory()->create(['email' => 'admin@example.com', 'password' => 'correct-horse']);
    $api = new FakeTelegramApi;

    foreach (range(1, 5) as $attempt) {
        $telegramUserId = 1000 + $attempt;
        RateLimiter::clear('telegram-login:user:'.$telegramUserId);
        startTelegramLogin($api, 'admin@example.com', $telegramUserId);
        (new LoginHandler($api))->handle(loginMessage('wrong', $telegramUserId));
    }

    RateLimiter::clear('telegram-login:user:2000');
    startTelegramLogin($api, 'admin@example.com', 2000);
    (new LoginHandler($api))->handle(loginMessage('correct-horse', 2000));

    expect($user->fresh()->telegram_id)->toBeNull()
        ->and(end($api->sentMessages)['text'])->toContain('محاولات دخول كثيرة');
});
