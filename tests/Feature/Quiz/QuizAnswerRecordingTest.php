<?php

use App\Jobs\ProcessTelegramUpdate;
use App\Models\DailyQuiz;
use App\Models\QuizAnswer;
use App\Models\QuizPlayer;
use App\Services\Quiz\QuizAnswerRecorder;
use Telegram\Bot\Api;
use Tests\Fakes\FakeTelegramApi;

class QuizAnswerRecordingJob extends ProcessTelegramUpdate
{
    protected function makeTelegram(): Api
    {
        return new FakeTelegramApi;
    }
}

/**
 * Feed one poll_answer update through the real job pipeline.
 */
function runPollAnswer(string $pollId, int $userId = 111, array $optionIds = [1], array $user = []): void
{
    (new QuizAnswerRecordingJob([
        'update_id' => random_int(1, PHP_INT_MAX),
        'poll_answer' => [
            'poll_id' => $pollId,
            'user' => [
                'id' => $userId,
                'is_bot' => false,
                'first_name' => 'سعد',
                'username' => 'saad',
                ...$user,
            ],
            'option_ids' => $optionIds,
        ],
    ]))->handle();
}

it('records a correct answer with base points and creates the player', function () {
    $quiz = DailyQuiz::factory()->posted()->create(['correct_option' => 1]);

    runPollAnswer($quiz->telegram_poll_id, optionIds: [1]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect($player)->not->toBeNull()
        ->and($player->first_name)->toBe('سعد')
        ->and($player->username)->toBe('saad')
        ->and($player->total_points)->toBe(QuizAnswerRecorder::POINTS_CORRECT)
        ->and($player->weekly_points)->toBe(QuizAnswerRecorder::POINTS_CORRECT)
        ->and($player->current_streak)->toBe(1)
        ->and($player->best_streak)->toBe(1)
        ->and($player->correct_count)->toBe(1)
        ->and($player->answers_count)->toBe(1)
        ->and($player->last_answered_on->isSameDay($quiz->quiz_date))->toBeTrue();

    $answer = QuizAnswer::query()->first();

    expect($answer->selected_option)->toBe(1)
        ->and($answer->is_correct)->toBeTrue()
        ->and($answer->points)->toBe(QuizAnswerRecorder::POINTS_CORRECT)
        ->and($answer->streak_at_answer)->toBe(1);
});

it('records a wrong answer with participation points', function () {
    $quiz = DailyQuiz::factory()->posted()->create(['correct_option' => 1]);

    runPollAnswer($quiz->telegram_poll_id, optionIds: [3]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect($player->total_points)->toBe(QuizAnswerRecorder::POINTS_WRONG)
        ->and($player->correct_count)->toBe(0)
        ->and($player->answers_count)->toBe(1)
        ->and(QuizAnswer::query()->first()->is_correct)->toBeFalse();
});

it('continues the streak when the previous quiz was answered', function () {
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDay()]);
    $quiz = DailyQuiz::factory()->posted()->create(['quiz_date' => today(), 'correct_option' => 1]);

    QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'current_streak' => 3,
        'best_streak' => 5,
        'total_points' => 50,
        'weekly_points' => 20,
        'last_answered_on' => today()->subDay(),
    ]);

    runPollAnswer($quiz->telegram_poll_id, optionIds: [1]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    // 10 base + min(4 - 1, 7) = 13
    expect($player->current_streak)->toBe(4)
        ->and($player->best_streak)->toBe(5)
        ->and($player->total_points)->toBe(50 + 13)
        ->and($player->weekly_points)->toBe(20 + 13);
});

it('caps the streak bonus', function () {
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDay()]);
    $quiz = DailyQuiz::factory()->posted()->create(['quiz_date' => today(), 'correct_option' => 1]);

    QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'current_streak' => 20,
        'best_streak' => 20,
        'last_answered_on' => today()->subDay(),
    ]);

    runPollAnswer($quiz->telegram_poll_id, optionIds: [1]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect($player->current_streak)->toBe(21)
        ->and($player->best_streak)->toBe(21)
        ->and($player->total_points)->toBe(QuizAnswerRecorder::POINTS_CORRECT + QuizAnswerRecorder::STREAK_BONUS_CAP);
});

it('resets the streak when the previous quiz was missed', function () {
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDay()]);
    $quiz = DailyQuiz::factory()->posted()->create(['quiz_date' => today(), 'correct_option' => 1]);

    QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'current_streak' => 6,
        'best_streak' => 6,
        'last_answered_on' => today()->subDays(2),
    ]);

    runPollAnswer($quiz->telegram_poll_id, optionIds: [1]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect($player->current_streak)->toBe(1)
        ->and($player->best_streak)->toBe(6)
        ->and($player->total_points)->toBe(QuizAnswerRecorder::POINTS_CORRECT);
});

it('keeps the streak across a day where no quiz was posted', function () {
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDays(2)]);
    $quiz = DailyQuiz::factory()->posted()->create(['quiz_date' => today(), 'correct_option' => 1]);

    QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'current_streak' => 2,
        'best_streak' => 2,
        'last_answered_on' => today()->subDays(2),
    ]);

    runPollAnswer($quiz->telegram_poll_id, optionIds: [1]);

    expect(QuizPlayer::query()->where('telegram_user_id', 111)->first()->current_streak)->toBe(3);
});

it('ignores a second vote from the same player on the same quiz', function () {
    $quiz = DailyQuiz::factory()->posted()->create(['correct_option' => 1]);

    runPollAnswer($quiz->telegram_poll_id, optionIds: [1]);
    runPollAnswer($quiz->telegram_poll_id, optionIds: [0]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect(QuizAnswer::query()->count())->toBe(1)
        ->and($player->total_points)->toBe(QuizAnswerRecorder::POINTS_CORRECT)
        ->and($player->answers_count)->toBe(1);
});

it('ignores votes on unknown polls', function () {
    runPollAnswer('some-unrelated-poll');

    expect(QuizPlayer::query()->count())->toBe(0)
        ->and(QuizAnswer::query()->count())->toBe(0);
});

it('ignores retracted votes', function () {
    $quiz = DailyQuiz::factory()->posted()->create();

    runPollAnswer($quiz->telegram_poll_id, optionIds: []);

    expect(QuizAnswer::query()->count())->toBe(0);
});

it('ignores votes from bots', function () {
    $quiz = DailyQuiz::factory()->posted()->create();

    runPollAnswer($quiz->telegram_poll_id, user: ['is_bot' => true]);

    expect(QuizAnswer::query()->count())->toBe(0);
});

it('refreshes the player name snapshot on each answer', function () {
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDay()]);
    $quiz = DailyQuiz::factory()->posted()->create(['quiz_date' => today()]);

    QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'first_name' => 'اسم قديم',
        'username' => 'old',
        'last_answered_on' => today()->subDay(),
    ]);

    runPollAnswer($quiz->telegram_poll_id, user: ['first_name' => 'اسم جديد', 'username' => 'fresh']);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect($player->first_name)->toBe('اسم جديد')
        ->and($player->username)->toBe('fresh');
});
