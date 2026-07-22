<?php

use App\Models\QuizPlayer;
use App\Services\Telegram\Handlers\QuizLeaderboardHandler;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

beforeEach(fn () => Cache::flush());

function leaderboardMessage(string $text, int $userId = 111, int $chatId = -100200300): Message
{
    return new Message([
        'message_id' => 10,
        'text' => $text,
        'chat' => ['id' => $chatId, 'type' => 'supergroup'],
        'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'سعد'],
    ]);
}

it('shows the weekly and all-time leaderboards with the asking player\'s standing', function (string $trigger) {
    QuizPlayer::factory()->create([
        'first_name' => 'أحمد',
        'weekly_points' => 40,
        'total_points' => 200,
        'current_streak' => 4,
        'answers_count' => 20,
    ]);
    QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'first_name' => 'سعد',
        'weekly_points' => 25,
        'total_points' => 90,
        'current_streak' => 2,
        'best_streak' => 6,
        'answers_count' => 9,
    ]);

    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage($trigger));

    expect($api->sentMessages)->toHaveCount(1);

    $text = $api->sentMessages[0]['text'];

    expect($text)->toContain('هذا الأسبوع')
        ->toContain('كل الأوقات')
        ->toContain('أحمد')
        ->toContain('🥇')
        ->toContain('نتيجتك')
        ->toContain('ترتيبك هذا الأسبوع: 2');
})->with(['المتصدرين', '/leaderboard', '/leaderboard@UquccTestBot']);

it('shows a teaching empty state when nobody has played yet', function () {
    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('المتصدرين'));

    expect($api->sentMessages)->toHaveCount(1)
        ->and($api->sentMessages[0]['text'])->toContain('شارك في سؤال اليوم');
});

it('omits the personal section for someone who never played', function () {
    QuizPlayer::factory()->create(['weekly_points' => 40, 'total_points' => 200, 'answers_count' => 20]);

    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('المتصدرين', userId: 999));

    expect($api->sentMessages[0]['text'])->not->toContain('نتيجتك');
});

it('rate-limits repeated leaderboard requests in the same chat', function () {
    QuizPlayer::factory()->create(['weekly_points' => 40, 'total_points' => 200, 'answers_count' => 20]);

    $api = new FakeTelegramApi;
    $handler = new QuizLeaderboardHandler($api);

    $handler->handle(leaderboardMessage('المتصدرين'));
    $handler->handle(leaderboardMessage('المتصدرين'));

    expect($api->sentMessages)->toHaveCount(1);

    // A different chat is on its own cooldown.
    $handler->handle(leaderboardMessage('المتصدرين', chatId: -100999888));

    expect($api->sentMessages)->toHaveCount(2);
});

it('ignores unrelated messages', function () {
    QuizPlayer::factory()->create(['weekly_points' => 40, 'answers_count' => 5]);

    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('كلام عادي عن المتصدرين في الدوري'));

    expect($api->sentMessages)->toBeEmpty();
});
