<?php

namespace App\Services\Quiz;

use App\Models\DailyQuiz;
use App\Models\QuizAnswer;
use App\Models\QuizPlayer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Telegram\Bot\Objects\PollAnswer;

/**
 * Turns a Telegram `poll_answer` update into points: records the vote on the
 * matching daily quiz and applies the scoring rules.
 *
 * Scoring: a correct answer earns {@see self::POINTS_CORRECT}, a wrong one
 * still earns {@see self::POINTS_WRONG} for showing up, and either gets a
 * streak bonus of (streak − 1) capped at {@see self::STREAK_BONUS_CAP}. The
 * streak counts consecutive *quizzes* answered, anchored to the previous
 * posted quiz's date rather than the calendar — a day where no quiz went out
 * (generation outage) breaks nobody's streak.
 *
 * A single missed quiz is forgiven by the streak freeze: it costs that day's
 * points but keeps the streak alive, once every
 * {@see self::FREEZE_COOLDOWN_DAYS} days. Without it one busy day wiped out
 * weeks of play and, because the streak bonus compounds, cost far more than
 * the day itself — the thing that made the standings feel unrecoverable.
 */
class QuizAnswerRecorder
{
    public const POINTS_CORRECT = 10;

    public const POINTS_WRONG = 2;

    public const STREAK_BONUS_CAP = 7;

    /** Minimum days between two streak freezes — one missed quiz a week. */
    public const FREEZE_COOLDOWN_DAYS = 7;

    public function record(PollAnswer $pollAnswer): void
    {
        $user = $pollAnswer->getUser();
        $optionIds = $pollAnswer->getOptionIds();

        if ($user === null || $user->getIsBot() || ! is_iterable($optionIds)) {
            return;
        }

        $optionIds = collect($optionIds)->values();

        if ($optionIds->isEmpty()) {
            return;
        }

        $quiz = DailyQuiz::findByPollId((string) $pollAnswer->getPollId());

        if ($quiz === null) {
            return;
        }

        try {
            DB::transaction(function () use ($quiz, $user, $optionIds): void {
                $player = QuizPlayer::query()->firstOrNew(['telegram_user_id' => $user->getId()]);

                $player->fill([
                    'first_name' => $user->getFirstName(),
                    'username' => $user->getUsername(),
                ])->save();

                $alreadyAnswered = QuizAnswer::query()
                    ->where('daily_quiz_id', $quiz->id)
                    ->where('quiz_player_id', $player->id)
                    ->exists();

                if ($alreadyAnswered) {
                    return;
                }

                if ($player->last_answered_on !== null && $player->last_answered_on->gte($quiz->quiz_date)) {
                    return;
                }

                ['streak' => $streak, 'frozen' => $frozen] = $this->streakFor($player, $quiz);
                $selected = (int) $optionIds->first();
                $isCorrect = $selected === $quiz->correct_option;
                $points = ($isCorrect ? self::POINTS_CORRECT : self::POINTS_WRONG)
                    + min($streak - 1, self::STREAK_BONUS_CAP);

                QuizAnswer::create([
                    'daily_quiz_id' => $quiz->id,
                    'quiz_player_id' => $player->id,
                    'selected_option' => $selected,
                    'is_correct' => $isCorrect,
                    'points' => $points,
                    'streak_at_answer' => $streak,
                    'answered_at' => now(),
                ]);

                $player->update([
                    'total_points' => $player->total_points + $points,
                    'weekly_points' => $player->weekly_points + $points,
                    'current_streak' => $streak,
                    'best_streak' => max($player->best_streak, $streak),
                    'streak_frozen_on' => $frozen ? $quiz->quiz_date : $player->streak_frozen_on,
                    'correct_count' => $player->correct_count + ($isCorrect ? 1 : 0),
                    'answers_count' => $player->answers_count + 1,
                    'last_answered_on' => $quiz->quiz_date,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent update already recorded this vote — nothing to do.
        }
    }

    /**
     * The player's streak as of this answer: it continues when they answered
     * the quiz right before this one, and — at the cost of a streak freeze —
     * when they answered the one before that and missed exactly one.
     *
     * @return array{streak: int, frozen: bool}
     */
    private function streakFor(QuizPlayer $player, DailyQuiz $quiz): array
    {
        if ($player->last_answered_on === null) {
            return ['streak' => 1, 'frozen' => false];
        }

        $previous = DailyQuiz::lastPostedBefore($quiz->quiz_date);

        if ($previous === null) {
            return ['streak' => 1, 'frozen' => false];
        }

        if ($player->last_answered_on->isSameDay($previous->quiz_date)) {
            return ['streak' => $player->current_streak + 1, 'frozen' => false];
        }

        if ($this->missedOnlyTheLastQuiz($player, $previous) && $this->freezeAvailable($player, $quiz)) {
            return ['streak' => $player->current_streak + 1, 'frozen' => true];
        }

        return ['streak' => 1, 'frozen' => false];
    }

    /**
     * True when the one quiz the player skipped is the one right before this
     * answer — they were here for the quiz before it. Two missed quizzes in a
     * row are a broken streak, freeze or not.
     */
    private function missedOnlyTheLastQuiz(QuizPlayer $player, DailyQuiz $previous): bool
    {
        $beforePrevious = DailyQuiz::lastPostedBefore($previous->quiz_date);

        return $beforePrevious !== null
            && $player->last_answered_on->isSameDay($beforePrevious->quiz_date);
    }

    private function freezeAvailable(QuizPlayer $player, DailyQuiz $quiz): bool
    {
        return $player->streak_frozen_on === null
            || $player->streak_frozen_on->lte($quiz->quiz_date->copy()->subDays(self::FREEZE_COOLDOWN_DAYS));
    }
}
