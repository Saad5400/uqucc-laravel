<?php

namespace Database\Factories;

use App\Models\OpinionPoll;
use App\Models\OpinionPollPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpinionPoll>
 */
class OpinionPollFactory extends Factory
{
    public function definition(): array
    {
        return [
            'poll_date' => today(),
            'question' => 'ما المحرر الذي تكتب به أكثر؟',
            'options' => ['VS Code', 'IntelliJ', 'Vim', 'شيء آخر'],
            'theme' => null,
            'status' => OpinionPoll::STATUS_READY,
            'post_time' => null,
        ];
    }

    /** Live in one group: the poll row plus an open post carrying its message. */
    public function posted(): static
    {
        return $this->state(fn (): array => [
            'status' => OpinionPoll::STATUS_POSTED,
            'posted_at' => now(),
            'closes_at' => now()->addDay(),
        ])->afterCreating(function (OpinionPoll $poll): void {
            if ($poll->posts()->doesntExist()) {
                OpinionPollPost::factory()->create(['opinion_poll_id' => $poll->id]);
            }
        });
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => OpinionPoll::STATUS_CLOSED,
            'posted_at' => now()->subDay(),
            'closes_at' => now(),
            'closed_at' => now(),
            'results' => [7, 3, 2, 1],
        ])->afterCreating(function (OpinionPoll $poll): void {
            if ($poll->posts()->doesntExist()) {
                OpinionPollPost::factory()->closed()->create(['opinion_poll_id' => $poll->id]);
            }
        });
    }
}
