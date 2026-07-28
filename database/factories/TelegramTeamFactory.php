<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\TelegramTeam>
 */
class TelegramTeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'chat_id' => $this->faker->numberBetween(-999_999_999, -100_000),
            'category_id' => null,
            'name' => $this->faker->unique()->words(2, true),
            'created_by_telegram_id' => $this->faker->numberBetween(10_000, 9_999_999),
        ];
    }
}
