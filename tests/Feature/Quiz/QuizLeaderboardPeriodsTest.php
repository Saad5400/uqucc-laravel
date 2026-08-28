<?php

use App\Models\DailyQuiz;
use App\Models\QuizAnswer;
use App\Models\QuizPlayer;
use App\Services\Quiz\QuizLeaderboard;

/**
 * The boards count questions, not clock time. A question goes out at 16:00
 * and takes votes until the next one replaces it the following afternoon, so
 * its answers land on both sides of midnight — and used to land in different
 * weeks, which let a member who answered after midnight outrank one who
 * answered the same question hours earlier.
 */
function board(): QuizLeaderboard
{
    return app(QuizLeaderboard::class);
}

/** One player's vote on one question, at a given moment. */
function voted(DailyQuiz $quiz, QuizPlayer $player, int $points, string $at): void
{
    QuizAnswer::factory()
        ->for($quiz, 'quiz')
        ->for($player, 'player')
        ->create(['points' => $points, 'answered_at' => $at]);
}

it('keeps both answers to one question in the same week, however late the vote', function () {
    // Wednesday's question — the last of the week, live until Thursday 16:00.
    $wednesday = DailyQuiz::factory()->posted()->create(['quiz_date' => '2026-08-26']);

    $early = QuizPlayer::factory()->create(['first_name' => 'مبكر']);
    $late = QuizPlayer::factory()->create(['first_name' => 'متأخر']);

    voted($wednesday, $early, 12, '2026-08-26 21:00:00');
    voted($wednesday, $late, 10, '2026-08-27 00:30:00');

    // Thursday morning: the week has not turned over, Wednesday's question is
    // still the one in play.
    $this->travelTo('2026-08-27 09:00:00');

    $weekly = board()->weekly(10);

    expect($weekly->pluck('first_name')->all())->toBe(['مبكر', 'متأخر'])
        ->and((int) $weekly->first()->weekly_points)->toBe(12)
        ->and(board()->weeklyPointsFor($early))->toBe(12)
        ->and(board()->weeklyPointsFor($late))->toBe(10)
        ->and(board()->weeklyRankFor($early))->toBe(1)
        ->and(board()->weeklyRankFor($late))->toBe(2);
});

it('turns the week over when the next question goes out, not at midnight', function () {
    $wednesday = DailyQuiz::factory()->posted()->create(['quiz_date' => '2026-08-26']);
    $player = QuizPlayer::factory()->create();

    voted($wednesday, $player, 10, '2026-08-27 00:30:00');

    $this->travelTo('2026-08-27 09:00:00');

    expect(board()->weekStart()->toDateString())->toBe('2026-08-20')
        ->and(board()->weeklyPointsFor($player))->toBe(10);

    // Thursday 16:00: the new question goes out, Wednesday's stops taking
    // votes, and only now does the weekly board start over.
    DailyQuiz::factory()->posted()->create(['quiz_date' => '2026-08-27']);
    $this->travelTo('2026-08-27 16:30:00');

    expect(board()->weekStart()->toDateString())->toBe('2026-08-27')
        ->and(board()->weeklyPointsFor($player))->toBe(0)
        ->and(board()->weekly(10))->toBeEmpty();
});

it('ages a question out of the rolling window whole, for everyone alike', function () {
    $today = DailyQuiz::factory()->posted()->create(['quiz_date' => today()]);
    $oldest = DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDays(QuizLeaderboard::WINDOW_DAYS - 1)]);
    $expired = DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDays(QuizLeaderboard::WINDOW_DAYS)]);

    $early = QuizPlayer::factory()->create(['first_name' => 'مبكر']);
    $late = QuizPlayer::factory()->create(['first_name' => 'متأخر']);

    // The same two members, answering each question early and late.
    voted($expired, $early, 100, today()->subDays(QuizLeaderboard::WINDOW_DAYS)->setTime(21, 0));
    voted($expired, $late, 100, today()->subDays(QuizLeaderboard::WINDOW_DAYS - 1)->setTime(0, 30));
    voted($oldest, $early, 5, today()->subDays(QuizLeaderboard::WINDOW_DAYS - 1)->setTime(21, 0));
    voted($oldest, $late, 5, today()->subDays(QuizLeaderboard::WINDOW_DAYS - 2)->setTime(0, 30));
    voted($today, $early, 7, today()->setTime(21, 0));

    expect(board()->windowPointsFor($early))->toBe(12)
        ->and(board()->windowPointsFor($late))->toBe(5);
});

it('ignores the hour a vote arrived entirely', function () {
    $quiz = DailyQuiz::factory()->posted()->create(['quiz_date' => today()]);
    $player = QuizPlayer::factory()->create();

    // A vote recorded with a wildly wrong clock still counts under its
    // question — the audit timestamp never decides a period.
    voted($quiz, $player, 10, today()->subDays(90)->toDateTimeString());

    expect(board()->weeklyPointsFor($player))->toBe(10)
        ->and(board()->windowPointsFor($player))->toBe(10);
});

it('falls back to today when no question has gone out yet', function () {
    DailyQuiz::factory()->create(['quiz_date' => today()->addDay()]);

    expect(board()->currentQuizDate()->toDateString())->toBe(today()->toDateString())
        ->and(board()->weekly(10))->toBeEmpty()
        ->and(board()->window(10))->toBeEmpty();
});
