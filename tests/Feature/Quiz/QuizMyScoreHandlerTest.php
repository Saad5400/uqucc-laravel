<?php

use App\Jobs\DeleteTelegramMessages;
use App\Models\QuizAnswer;
use App\Models\QuizPlayer;
use App\Services\Quiz\QuizAnswerRecorder;
use App\Services\Telegram\Handlers\QuizMyScoreHandler;
use Illuminate\Support\Facades\Bus;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

function myScoreMessage(string $text, int $userId = 111): Message
{
    return new Message([
        'message_id' => 10,
        'text' => $text,
        'chat' => ['id' => -100200300, 'type' => 'supergroup'],
        'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'سعد'],
    ]);
}

it('replies with the player\'s own standing and schedules deletion', function (string $trigger) {
    Bus::fake();

    QuizPlayer::factory()->create(['weekly_points' => 30]);
    $player = QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'first_name' => 'سعد',
        'weekly_points' => 25,
        'total_points' => 90,
        'current_streak' => 3,
        'best_streak' => 6,
        'correct_count' => 7,
        'answers_count' => 9,
    ]);

    QuizAnswer::factory()->for($player, 'player')->create(['points' => 25, 'answered_at' => now()->subDay()]);
    QuizAnswer::factory()->for($player, 'player')->create(['points' => 40, 'answered_at' => now()->subDays(60)]);

    $api = new FakeTelegramApi;
    (new QuizMyScoreHandler($api))->handle(myScoreMessage($trigger));

    expect($api->sentMessages)->toHaveCount(1);

    $text = $api->sentMessages[0]['text'];

    expect($text)->toContain('نتيجتك في سؤال اليوم')
        ->toContain('ترتيبك 2')
        // Only the answer inside the window counts; the 60-day-old one does not.
        ->toContain('آخر 30 يوماً: 25 نقطة')
        ->toContain('الإجمالي منذ البداية: 90 نقطة')
        ->toContain('3 أيام')
        ->toContain('7 من 9')
        ->toContain('تجميدة السلسلة جاهزة');

    Bus::assertDispatched(DeleteTelegramMessages::class);
})->with(['نقاطي', '/myscore', '/mypoints@UquccTestBot']);

it('tells a player whose streak freeze is spent when it comes back', function () {
    Bus::fake();

    QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'weekly_points' => 25,
        'answers_count' => 9,
        'streak_frozen_on' => today()->subDays(QuizAnswerRecorder::FREEZE_COOLDOWN_DAYS - 2),
    ]);

    $api = new FakeTelegramApi;
    (new QuizMyScoreHandler($api))->handle(myScoreMessage('نقاطي'));

    expect($api->sentMessages[0]['text'])->toContain('استُخدمت تجميدة السلسلة')
        ->toContain('يومان');
});

it('teaches a member who has not played yet', function () {
    Bus::fake();

    $api = new FakeTelegramApi;
    (new QuizMyScoreHandler($api))->handle(myScoreMessage('نقاطي'));

    expect($api->sentMessages)->toHaveCount(1)
        ->and($api->sentMessages[0]['text'])->toContain('لم تشارك');

    Bus::assertDispatched(DeleteTelegramMessages::class);
});

it('ignores unrelated messages', function () {
    Bus::fake();

    $api = new FakeTelegramApi;
    (new QuizMyScoreHandler($api))->handle(myScoreMessage('كم نقاطي في اللعبة الأخرى؟'));

    expect($api->sentMessages)->toBeEmpty();
});
