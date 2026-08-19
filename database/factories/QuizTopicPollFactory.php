<?php

namespace Database\Factories;

use App\Models\QuizTopic;
use App\Models\QuizTopicPoll;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizTopicPoll>
 */
class QuizTopicPollFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quiz_date' => today()->addDay(),
            // A closure so a ballot handed explicit topics never also conjures
            // four throwaway ones.
            'topic_ids' => fn (): array => QuizTopic::factory()->count(4)->create()->modelKeys(),
            'posts' => [[
                'chat_id' => -100200300,
                'message_id' => 4242,
                'message_thread_id' => null,
                'telegram_poll_id' => '9001',
            ]],
            'quiz_topic_id' => null,
            'closed_at' => null,
        ];
    }

    /** A ballot listing exactly these topics, in this order. */
    public function forTopics(iterable $topics): static
    {
        return $this->state(fn (): array => [
            'topic_ids' => collect($topics)->map(fn (QuizTopic $topic): int => $topic->id)->all(),
        ]);
    }

    /** A ballot delivered to these chats, one poll message each. */
    public function inChats(int ...$chatIds): static
    {
        return $this->state(fn (): array => [
            'posts' => collect($chatIds)
                ->values()
                ->map(fn (int $chatId, int $index): array => [
                    'chat_id' => $chatId,
                    'message_id' => 4242 + $index,
                    'message_thread_id' => null,
                    'telegram_poll_id' => (string) (9001 + $index),
                ])
                ->all(),
        ]);
    }
}
