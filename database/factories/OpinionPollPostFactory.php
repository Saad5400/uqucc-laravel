<?php

namespace Database\Factories;

use App\Models\OpinionPoll;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\OpinionPollPost>
 */
class OpinionPollPostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'opinion_poll_id' => OpinionPoll::factory(),
            'chat_id' => -100200300,
            'message_id' => $this->faker->unique()->numberBetween(1, 100000),
            'message_thread_id' => null,
            'telegram_poll_id' => 'opinion-poll-'.$this->faker->unique()->numberBetween(1000, 999999),
            'votes' => null,
            'posted_at' => now(),
            'closed_at' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'votes' => [7, 3, 2, 1],
            'closed_at' => now(),
        ]);
    }
}
