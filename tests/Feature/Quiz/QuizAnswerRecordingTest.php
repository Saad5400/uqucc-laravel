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

/** The Telegram poll id of the quiz's (first) live post. */
function pollIdOf(DailyQuiz $quiz): string
{
    return $quiz->posts()->first()->telegram_poll_id;
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

/**
 * What one correct answer is worth: the base points, the bonus for the speed
 * rank it took (`null` for one that placed outside the paying ranks), and the
 * streak bonus on top.
 */
function correctPoints(?int $speedRank = 1, int $streakBonus = 0): int
{
    return QuizAnswerRecorder::POINTS_CORRECT
        + QuizAnswerRecorder::speedBonusFor($speedRank)
        + $streakBonus;
}

it('records a correct answer with base points and creates the player', function () {
    $quiz = DailyQuiz::factory()->posted()->create(['correct_option' => 1]);

    runPollAnswer(pollIdOf($quiz), optionIds: [1]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect($player)->not->toBeNull()
        ->and($player->first_name)->toBe('سعد')
        ->and($player->username)->toBe('saad')
        ->and($player->total_points)->toBe(correctPoints())
        ->and($player->current_streak)->toBe(1)
        ->and($player->best_streak)->toBe(1)
        ->and($player->correct_count)->toBe(1)
        ->and($player->answers_count)->toBe(1)
        ->and($player->last_answered_on->isSameDay($quiz->quiz_date))->toBeTrue();

    $answer = QuizAnswer::query()->first();

    expect($answer->selected_option)->toBe(1)
        ->and($answer->is_correct)->toBeTrue()
        ->and($answer->points)->toBe(correctPoints())
        ->and($answer->streak_at_answer)->toBe(1)
        ->and($answer->speed_rank)->toBe(1);
});

it('pays the first five correct answers a speed bonus by order, and nothing after that', function () {
    $quiz = DailyQuiz::factory()->posted()->create(['correct_option' => 1]);
    $racers = QuizAnswerRecorder::speedRanksCount() + 1;

    foreach (range(1, $racers) as $seat) {
        runPollAnswer(pollIdOf($quiz), userId: 1000 + $seat, optionIds: [1]);
    }

    $answers = QuizAnswer::query()->orderBy('id')->get();

    expect($answers)->toHaveCount($racers)
        ->and($answers->pluck('speed_rank')->all())->toBe([1, 2, 3, 4, 5, null])
        ->and($answers->pluck('points')->all())->toBe([
            correctPoints(1), correctPoints(2), correctPoints(3),
            correctPoints(4), correctPoints(5), correctPoints(null),
        ])
        // The head start is worth having: half a correct answer again.
        ->and($answers->first()->points)->toBe(QuizAnswerRecorder::POINTS_CORRECT + 5);
});

it('lets a fast answer outscore a slower one on the same question', function () {
    $quiz = DailyQuiz::factory()->posted()->create(['correct_option' => 1]);

    runPollAnswer(pollIdOf($quiz), userId: 111, optionIds: [1]);

    foreach (range(1, QuizAnswerRecorder::speedRanksCount()) as $filler) {
        runPollAnswer(pollIdOf($quiz), userId: 2000 + $filler, optionIds: [1]);
    }

    runPollAnswer(pollIdOf($quiz), userId: 222, optionIds: [1]);

    $fast = QuizPlayer::query()->where('telegram_user_id', 111)->first();
    $slow = QuizPlayer::query()->where('telegram_user_id', 222)->first();

    expect($fast->total_points)->toBeGreaterThan($slow->total_points);
});

it('gives a wrong answer no speed bonus and leaves the rank for the next correct one', function () {
    $quiz = DailyQuiz::factory()->posted()->create(['correct_option' => 1]);

    runPollAnswer(pollIdOf($quiz), userId: 111, optionIds: [3]);
    runPollAnswer(pollIdOf($quiz), userId: 222, optionIds: [1]);

    $guesser = QuizAnswer::query()->whereRelation('player', 'telegram_user_id', 111)->first();
    $winner = QuizAnswer::query()->whereRelation('player', 'telegram_user_id', 222)->first();

    expect($guesser->speed_rank)->toBeNull()
        ->and($guesser->points)->toBe(QuizAnswerRecorder::POINTS_WRONG)
        ->and($winner->speed_rank)->toBe(1)
        ->and($winner->points)->toBe(correctPoints());
});

it('starts a new race on each question', function () {
    $yesterday = DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDay(), 'correct_option' => 1]);
    $today = DailyQuiz::factory()->posted()->create(['quiz_date' => today(), 'correct_option' => 1]);

    foreach (range(1, QuizAnswerRecorder::speedRanksCount()) as $filler) {
        runPollAnswer(pollIdOf($yesterday), userId: 3000 + $filler, optionIds: [1]);
    }

    runPollAnswer(pollIdOf($today), userId: 111, optionIds: [1]);

    expect(QuizAnswer::query()->where('daily_quiz_id', $today->id)->first()->speed_rank)->toBe(1);
});

it('records a wrong answer with participation points', function () {
    $quiz = DailyQuiz::factory()->posted()->create(['correct_option' => 1]);

    runPollAnswer(pollIdOf($quiz), optionIds: [3]);

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
        'last_answered_on' => today()->subDay(),
    ]);

    runPollAnswer(pollIdOf($quiz), optionIds: [1]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    // 10 base + the day's top speed bonus + min(4 - 1, 7).
    expect($player->current_streak)->toBe(4)
        ->and($player->best_streak)->toBe(5)
        ->and($player->total_points)->toBe(50 + correctPoints(1, 3));
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

    runPollAnswer(pollIdOf($quiz), optionIds: [1]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect($player->current_streak)->toBe(21)
        ->and($player->best_streak)->toBe(21)
        ->and($player->total_points)->toBe(correctPoints(1, QuizAnswerRecorder::STREAK_BONUS_CAP));
});

it('resets the streak when the previous quiz was missed and the freeze is spent', function () {
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDays(2)]);
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDay()]);
    $quiz = DailyQuiz::factory()->posted()->create(['quiz_date' => today(), 'correct_option' => 1]);

    QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'current_streak' => 6,
        'best_streak' => 6,
        'last_answered_on' => today()->subDays(2),
        'streak_frozen_on' => today()->subDays(3),
    ]);

    runPollAnswer(pollIdOf($quiz), optionIds: [1]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect($player->current_streak)->toBe(1)
        ->and($player->best_streak)->toBe(6)
        ->and($player->total_points)->toBe(correctPoints())
        ->and($player->streak_frozen_on->isSameDay(today()->subDays(3)))->toBeTrue();
});

it('forgives a single missed quiz with the streak freeze', function () {
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDays(2)]);
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDay()]);
    $quiz = DailyQuiz::factory()->posted()->create(['quiz_date' => today(), 'correct_option' => 1]);

    QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'current_streak' => 6,
        'best_streak' => 6,
        'last_answered_on' => today()->subDays(2),
    ]);

    runPollAnswer(pollIdOf($quiz), optionIds: [1]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    // The streak survives the gap: base + speed + min(7 - 1, 7).
    expect($player->current_streak)->toBe(7)
        ->and($player->best_streak)->toBe(7)
        ->and($player->total_points)->toBe(correctPoints(1, 6))
        ->and($player->streak_frozen_on->isSameDay(today()))->toBeTrue();
});

it('breaks the streak when two quizzes in a row are missed', function () {
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDays(3)]);
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDays(2)]);
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDay()]);
    $quiz = DailyQuiz::factory()->posted()->create(['quiz_date' => today(), 'correct_option' => 1]);

    QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'current_streak' => 6,
        'best_streak' => 6,
        'last_answered_on' => today()->subDays(3),
    ]);

    runPollAnswer(pollIdOf($quiz), optionIds: [1]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect($player->current_streak)->toBe(1)
        ->and($player->streak_frozen_on)->toBeNull();
});

it('offers the streak freeze again once the cooldown has passed', function () {
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDays(2)]);
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDay()]);
    $quiz = DailyQuiz::factory()->posted()->create(['quiz_date' => today(), 'correct_option' => 1]);

    QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'current_streak' => 6,
        'best_streak' => 6,
        'last_answered_on' => today()->subDays(2),
        'streak_frozen_on' => today()->subDays(QuizAnswerRecorder::FREEZE_COOLDOWN_DAYS),
    ]);

    runPollAnswer(pollIdOf($quiz), optionIds: [1]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect($player->current_streak)->toBe(7)
        ->and($player->streak_frozen_on->isSameDay(today()))->toBeTrue();
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

    runPollAnswer(pollIdOf($quiz), optionIds: [1]);

    expect(QuizPlayer::query()->where('telegram_user_id', 111)->first()->current_streak)->toBe(3);
});

it('ignores a second vote from the same player on the same quiz', function () {
    $quiz = DailyQuiz::factory()->posted()->create(['correct_option' => 1]);

    runPollAnswer(pollIdOf($quiz), optionIds: [1]);
    runPollAnswer(pollIdOf($quiz), optionIds: [0]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect(QuizAnswer::query()->count())->toBe(1)
        ->and($player->total_points)->toBe(correctPoints())
        ->and($player->answers_count)->toBe(1);
});

it('counts only the first vote when a member answers in two groups', function () {
    $quiz = DailyQuiz::factory()->posted()->create(['correct_option' => 1]);
    $secondPost = \App\Models\QuizPost::factory()->create(['daily_quiz_id' => $quiz->id, 'chat_id' => -100400500]);

    runPollAnswer(pollIdOf($quiz), optionIds: [1]);
    runPollAnswer($secondPost->telegram_poll_id, optionIds: [0]);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect(QuizAnswer::query()->count())->toBe(1)
        ->and($player->total_points)->toBe(correctPoints())
        ->and($player->answers_count)->toBe(1);
});

it('ignores votes on unknown polls', function () {
    runPollAnswer('some-unrelated-poll');

    expect(QuizPlayer::query()->count())->toBe(0)
        ->and(QuizAnswer::query()->count())->toBe(0);
});

it('ignores retracted votes', function () {
    $quiz = DailyQuiz::factory()->posted()->create();

    runPollAnswer(pollIdOf($quiz), optionIds: []);

    expect(QuizAnswer::query()->count())->toBe(0);
});

it('ignores votes from bots', function () {
    $quiz = DailyQuiz::factory()->posted()->create();

    runPollAnswer(pollIdOf($quiz), user: ['is_bot' => true]);

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

    runPollAnswer(pollIdOf($quiz), user: ['first_name' => 'اسم جديد', 'username' => 'fresh']);

    $player = QuizPlayer::query()->where('telegram_user_id', 111)->first();

    expect($player->first_name)->toBe('اسم جديد')
        ->and($player->username)->toBe('fresh');
});
