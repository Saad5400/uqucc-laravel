<?php

use App\Models\DailyQuiz;
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
        'weekly_points' => 40,
        'total_points' => 200,
        'current_streak' => 4,
        'answers_count' => 20,
    ]);
    $saad = QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'first_name' => 'سعد',
        'weekly_points' => 25,
        'total_points' => 90,
        'current_streak' => 2,
        'best_streak' => 6,
        'answers_count' => 9,
    ]);

    $earlier = DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDays(3)]);
    $later = DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDay()]);

    QuizAnswer::factory()->for($ahmed, 'player')->for($earlier, 'quiz')->create([
        'points' => 40,
        'answered_at' => now()->subDays(3),
    ]);
    QuizAnswer::factory()->for($saad, 'player')->for($later, 'quiz')->create([
        'points' => 25,
        'answered_at' => now()->subDay(),
    ]);

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

    $old = DailyQuiz::factory()->closed()->create([
        'quiz_date' => today()->subDays(QuizLeaderboard::WINDOW_DAYS + 1),
    ]);
    $fresh = DailyQuiz::factory()->posted()->create(['quiz_date' => today()]);

    QuizAnswer::factory()->for($veteran, 'player')->for($old, 'quiz')->create([
        'points' => 5000,
        'answered_at' => now()->subDays(QuizLeaderboard::WINDOW_DAYS + 1),
    ]);
    QuizAnswer::factory()->for($newcomer, 'player')->for($fresh, 'quiz')->create([
        'points' => 60,
        'answered_at' => now(),
    ]);

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
