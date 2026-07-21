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
 */
class QuizAnswerRecorder
{
    public const POINTS_CORRECT = 10;

    public const POINTS_WRONG = 2;

    public const STREAK_BONUS_CAP = 7;

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

                $streak = $this->streakFor($player, $quiz);
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
     * The player's streak as of this answer: continues only when they also
     * answered the quiz that came right before this one.
     */
    private function streakFor(QuizPlayer $player, DailyQuiz $quiz): int
    {
        if ($player->last_answered_on === null) {
            return 1;
        }

        $previous = DailyQuiz::lastPostedBefore($quiz->quiz_date);

        if ($previous === null || ! $player->last_answered_on->isSameDay($previous->quiz_date)) {
            return 1;
        }

        return $player->current_streak + 1;
    }
}
