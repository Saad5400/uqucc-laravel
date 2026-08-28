<?php

namespace Database\Factories;

use App\Models\DailyQuiz;
use App\Models\QuizPlayer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\QuizAnswer>
 */
class QuizAnswerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'daily_quiz_id' => fn (): int => $this->quizOn(today())->id,
            'quiz_player_id' => QuizPlayer::factory(),
            'selected_option' => 1,
            'is_correct' => true,
            'points' => 10,
            'streak_at_answer' => 1,
            'answered_at' => now(),
        ];
    }

    public function wrong(): static
    {
        return $this->state(fn (): array => [
            'selected_option' => 0,
            'is_correct' => false,
            'points' => 2,
        ]);
    }

    /**
     * The answer belongs to the question of a given day — the unit the
     * leaderboards count by. `answered_at` follows the quiz day by default
     * and can be overridden on its own to place the vote at a particular
     * hour, which is how the boards are tested across midnight.
     */
    public function onQuizDate(CarbonInterface $date): static
    {
        return $this->state(fn (): array => [
            'daily_quiz_id' => $this->quizOn($date)->id,
            'answered_at' => $date,
        ]);
    }

    /**
     * That day's question, created on first use. `quiz_date` is unique, so
     * every player answering the same day shares one question row.
     */
    private function quizOn(CarbonInterface $date): DailyQuiz
    {
        return DailyQuiz::query()->whereDate('quiz_date', $date)->first()
            ?? DailyQuiz::factory()->posted()->create(['quiz_date' => $date]);
    }
}
