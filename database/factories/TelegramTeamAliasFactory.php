<?php

namespace Database\Factories;

use App\Models\TelegramTeam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\TelegramTeamAlias>
 */
class TelegramTeamAliasFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => TelegramTeam::factory(),
            'chat_id' => $this->faker->numberBetween(-999_999_999, -100_000),
            'name' => $this->faker->unique()->word(),
        ];
    }

    /**
     * Keep the alias in the same chat as the team it belongs to.
     */
    public function forTeam(TelegramTeam $team): static
    {
        return $this->state(fn (): array => [
            'team_id' => $team->id,
            'chat_id' => $team->chat_id,
        ]);
    }
}
