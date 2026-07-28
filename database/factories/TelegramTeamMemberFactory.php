<?php

namespace Database\Factories;

use App\Models\TelegramTeam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\TelegramTeamMember>
 */
class TelegramTeamMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => TelegramTeam::factory(),
            'telegram_user_id' => $this->faker->numberBetween(10_000, 9_999_999),
            'first_name' => $this->faker->firstName(),
            'username' => $this->faker->optional()->userName(),
            'consent_message_id' => $this->faker->numberBetween(1, 999_999),
            'consented_at' => now(),
            'added_by_telegram_id' => $this->faker->numberBetween(10_000, 9_999_999),
        ];
    }
}
