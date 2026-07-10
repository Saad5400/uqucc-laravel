<?php

namespace Database\Factories;

use App\Models\BotCommandStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotCommandStat>
 */
class BotCommandStatFactory extends Factory
{
    protected $model = BotCommandStat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'telegram_user_id' => fake()->unique()->numberBetween(1_000_000, 999_999_999),
            'command_name' => fake()->randomElement(['/start', '/help', '/search', '/pages']),
            'chat_type' => 'private',
            'chat_id' => fake()->numberBetween(1_000_000, 999_999_999),
            'count' => fake()->numberBetween(1, 20),
            'last_used_at' => now(),
        ];
    }
}
