<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\QuizPlayer>
 */
class QuizPlayerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'telegram_user_id' => $this->faker->unique()->numberBetween(10_000, 9_999_999),
            'first_name' => $this->faker->firstName(),
            'username' => $this->faker->optional()->userName(),
            'major' => null,
            'total_points' => 0,
            'weekly_points' => 0,
            'current_streak' => 0,
            'best_streak' => 0,
            'correct_count' => 0,
            'answers_count' => 0,
            'last_answered_on' => null,
        ];
    }
}
