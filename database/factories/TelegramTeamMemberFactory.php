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
            // Null by default so mention rendering is deterministic in tests;
            // use the withUsername() state for the @mention path.
            'username' => null,
            'consent_message_id' => $this->faker->numberBetween(1, 999_999),
            'consented_at' => now(),
            'added_by_telegram_id' => $this->faker->numberBetween(10_000, 9_999_999),
        ];
    }

    public function withUsername(string $username): static
    {
        return $this->state(fn (): array => ['username' => $username]);
    }
}
