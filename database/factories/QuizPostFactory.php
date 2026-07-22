<?php

namespace Database\Factories;

use App\Models\DailyQuiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\QuizPost>
 */
class QuizPostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'daily_quiz_id' => DailyQuiz::factory(),
            'chat_id' => -100200300,
            'message_id' => $this->faker->unique()->numberBetween(1, 100000),
            'telegram_poll_id' => 'poll-'.$this->faker->unique()->numberBetween(1000, 999999),
            'posted_at' => now(),
            'closed_at' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['closed_at' => now()]);
    }
}
