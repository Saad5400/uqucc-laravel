<?php

use App\Helpers\Bidi;
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

it('replies with the player\'s own standing as an ephemeral group message', function (string $trigger) {
    Bus::fake();

    $rival = QuizPlayer::factory()->create();
    $player = QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'first_name' => 'سعد',
        'total_points' => 90,
        'current_streak' => 3,
        'best_streak' => 6,
        'correct_count' => 7,
        'answers_count' => 9,
    ]);

    QuizAnswer::factory()->for($rival, 'player')->onQuizDate(today())->create(['points' => 30]);
    QuizAnswer::factory()->for($player, 'player')->onQuizDate(today())->create(['points' => 25]);
    QuizAnswer::factory()->for($player, 'player')->onQuizDate(today()->subDays(60))->create(['points' => 40]);

    $api = new FakeTelegramApi;
    (new QuizMyScoreHandler($api))->handle(myScoreMessage($trigger));

    expect($api->sentMessages)->toHaveCount(1);

    $text = $api->sentMessages[0]['text'];
    $ephemeral = json_decode($api->sentMessages[0]['ephemeral_message_parameters'], true, flags: JSON_THROW_ON_ERROR);

    expect($text)->toContain('نتيجتك في سؤال اليوم')
        ->toContain('ترتيبك 2')
        // Only the answer inside the window counts; the 60-day-old one does not.
        ->toContain('آخر 30 يوماً: 25 نقطة')
        ->toContain('الإجمالي منذ البداية: 90 نقطة')
        ->toContain('3 أيام')
        ->toContain('7 من 9')
        ->toContain('تجميدة السلسلة جاهزة')
        ->and($ephemeral)->toBe(['receiver_user_id' => 111]);

    Bus::assertDispatched(
        DeleteTelegramMessages::class,
        fn (DeleteTelegramMessages $job): bool => $job->messageIds === [10],
    );
})->with(['نقاطي', '/myscore', '/mypoints@UquccTestBot']);

it('shows what the current streak adds to every answer', function () {
    Bus::fake();

    QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'answers_count' => 9,
        'current_streak' => 5,
    ]);

    $api = new FakeTelegramApi;
    (new QuizMyScoreHandler($api))->handle(myScoreMessage('نقاطي'));

    // The value is bidi-fenced so «+4» keeps its sign on the left.
    expect($api->sentMessages[0]['text'])->toContain('تضيف '.Bidi::ltr('+4').' لكل إجابة');
});

it('counts the days the player was among the fastest', function () {
    Bus::fake();

    $player = QuizPlayer::factory()->create(['telegram_user_id' => 111, 'answers_count' => 3]);

    QuizAnswer::factory()->for($player, 'player')->fastest(1)->onQuizDate(today())->create();
    QuizAnswer::factory()->for($player, 'player')->fastest(4)->onQuizDate(today()->subDay())->create();
    QuizAnswer::factory()->for($player, 'player')->onQuizDate(today()->subDays(2))->create();

    $api = new FakeTelegramApi;
    (new QuizMyScoreHandler($api))->handle(myScoreMessage('نقاطي'));

    expect($api->sentMessages[0]['text'])->toContain('ضمن أسرع الإجابات: مرتان');
});

it('teaches the speed bonus to a player who has never made the ranks', function () {
    Bus::fake();

    $player = QuizPlayer::factory()->create(['telegram_user_id' => 111, 'answers_count' => 1]);
    QuizAnswer::factory()->for($player, 'player')->onQuizDate(today())->create();

    $api = new FakeTelegramApi;
    (new QuizMyScoreHandler($api))->handle(myScoreMessage('نقاطي'));

    expect($api->sentMessages[0]['text'])->toContain('أول 5 إجابات صحيحة');
});

it('tells a player whose streak freeze is spent when it comes back', function () {
    Bus::fake();

    QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
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

it('falls back to the existing auto-deleting reply when Telegram rejects ephemeral delivery', function () {
    Bus::fake();

    $api = new FakeTelegramApi;
    $api->sendMessageFailures = ['Bad Request: ephemeral messages are unavailable', null];

    (new QuizMyScoreHandler($api))->handle(myScoreMessage('نقاطي'));

    expect($api->sentMessages)->toHaveCount(1)
        ->and($api->sentMessages[0])->not->toHaveKey('ephemeral_message_parameters');

    Bus::assertDispatched(
        DeleteTelegramMessages::class,
        fn (DeleteTelegramMessages $job): bool => $job->messageIds === [10, 1001],
    );
});

it('uses an ephemeral message id when replying to an ephemeral command', function () {
    Bus::fake();

    $api = new FakeTelegramApi;
    $message = myScoreMessage('نقاطي');
    $message = new Message(array_merge($message->getRawResponse(), [
        'message_id' => null,
        'ephemeral_message_id' => 77,
    ]));

    (new QuizMyScoreHandler($api))->handle($message);

    $reply = json_decode($api->sentMessages[0]['reply_parameters'], true, flags: JSON_THROW_ON_ERROR);

    expect($reply)->toBe(['ephemeral_message_id' => 77])
        ->and($api->sentMessages[0])->not->toHaveKey('reply_to_message_id');

    Bus::assertNotDispatched(DeleteTelegramMessages::class);
});

it('ignores unrelated messages', function () {
    Bus::fake();

    $api = new FakeTelegramApi;
    (new QuizMyScoreHandler($api))->handle(myScoreMessage('كم نقاطي في اللعبة الأخرى؟'));

    expect($api->sentMessages)->toBeEmpty();
});
