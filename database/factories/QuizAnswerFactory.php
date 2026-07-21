<?php

namespace Database\Factories;

use App\Models\DailyQuiz;
use App\Models\QuizPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\QuizAnswer>
 */
class QuizAnswerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'daily_quiz_id' => DailyQuiz::factory()->posted(),
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
}
