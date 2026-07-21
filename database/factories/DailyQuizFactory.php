<?php

namespace Database\Factories;

use App\Models\DailyQuiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyQuiz>
 */
class DailyQuizFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quiz_topic_id' => null,
            'quiz_date' => today(),
            'question' => 'ما هي نتيجة 2 + 2 في النظام العشري؟',
            'options' => ['3', '4', '5', '22'],
            'correct_option' => 1,
            'explanation' => 'الجمع الحسابي البسيط: 2 + 2 = 4.',
            'status' => DailyQuiz::STATUS_READY,
        ];
    }

    public function posted(): static
    {
        return $this->state(fn (): array => [
            'status' => DailyQuiz::STATUS_POSTED,
            'telegram_poll_id' => 'poll-'.$this->faker->unique()->numberBetween(1000, 999999),
            'chat_id' => -100200300,
            'message_id' => $this->faker->numberBetween(1, 100000),
            'posted_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->posted()->state(fn (): array => [
            'status' => DailyQuiz::STATUS_CLOSED,
            'closed_at' => now(),
        ]);
    }
}
