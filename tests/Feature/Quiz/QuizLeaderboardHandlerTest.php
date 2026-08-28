<?php

use App\Models\QuizAnswer;
use App\Models\QuizPlayer;
use App\Services\Quiz\QuizLeaderboard;
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

it('shows the weekly and rolling leaderboards with the asking player\'s standing', function (string $trigger) {
    $ahmed = QuizPlayer::factory()->create([
        'first_name' => 'أحمد',
        'total_points' => 200,
        'current_streak' => 4,
        'answers_count' => 20,
    ]);
    $saad = QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'first_name' => 'سعد',
        'total_points' => 90,
        'current_streak' => 2,
        'best_streak' => 6,
        'answers_count' => 9,
    ]);

    QuizAnswer::factory()->for($ahmed, 'player')->onQuizDate(today()->subDay())->create(['points' => 40]);
    QuizAnswer::factory()->for($saad, 'player')->onQuizDate(today())->create(['points' => 25]);

    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage($trigger));

    expect($api->sentMessages)->toHaveCount(1);

    $text = $api->sentMessages[0]['text'];

    expect($text)->toContain('هذا الأسبوع')
        ->toContain('آخر 30 يوماً')
        ->not->toContain('كل الأوقات')
        ->and($text)->toContain('أحمد')
        ->toContain('🥇')
        ->toContain('نتيجتك')
        ->toContain('هذا الأسبوع: 25 نقطة (ترتيبك 2)')
        ->toContain('آخر 30 يوماً: 25 نقطة (ترتيبك 2)');
})->with(['المتصدرين', '/leaderboard', '/leaderboard@UquccTestBot']);

it('ranks the rolling board on the window only, so an old lead ages out', function () {
    $veteran = QuizPlayer::factory()->create([
        'first_name' => 'قديم',
        'total_points' => 5000,
        'answers_count' => 300,
    ]);
    $newcomer = QuizPlayer::factory()->create([
        'first_name' => 'جديد',
        'total_points' => 60,
        'answers_count' => 5,
    ]);

    QuizAnswer::factory()
        ->for($veteran, 'player')
        ->onQuizDate(today()->subDays(QuizLeaderboard::WINDOW_DAYS))
        ->create(['points' => 5000]);
    QuizAnswer::factory()->for($newcomer, 'player')->onQuizDate(today())->create(['points' => 60]);

    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('المتصدرين', userId: 999));

    $text = $api->sentMessages[0]['text'];

    expect($text)->toContain('🥇 جديد')
        ->not->toContain('قديم');
});

it('shows a teaching empty state when nobody has played yet', function () {
    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('المتصدرين'));

    expect($api->sentMessages)->toHaveCount(1)
        ->and($api->sentMessages[0]['text'])->toContain('شارك في سؤال اليوم');
});

it('omits the personal section for someone who never played', function () {
    QuizPlayer::factory()->create(['total_points' => 200, 'answers_count' => 20]);

    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('المتصدرين', userId: 999));

    expect($api->sentMessages[0]['text'])->not->toContain('نتيجتك');
});

it('rate-limits repeated leaderboard requests in the same chat', function () {
    QuizPlayer::factory()->create(['total_points' => 200, 'answers_count' => 20]);

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
    QuizPlayer::factory()->create(['answers_count' => 5]);

    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('كلام عادي عن المتصدرين في الدوري'));

    expect($api->sentMessages)->toBeEmpty();
});
