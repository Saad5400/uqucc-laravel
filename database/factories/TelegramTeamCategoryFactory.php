<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\TelegramTeamCategory>
 */
class TelegramTeamCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'chat_id' => $this->faker->numberBetween(-999_999_999, -100_000),
            'name' => $this->faker->unique()->words(2, true),
        ];
    }
}
