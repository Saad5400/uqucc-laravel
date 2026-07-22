<?php

namespace Database\Factories;

use App\Models\DailyQuiz;
use App\Models\QuizPost;
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

    /**
     * Posted to one group: the quiz row plus a live QuizPost carrying the
     * Telegram identifiers.
     */
    public function posted(): static
    {
        return $this->state(fn (): array => [
            'status' => DailyQuiz::STATUS_POSTED,
            'posted_at' => now(),
        ])->afterCreating(function (DailyQuiz $quiz): void {
            if ($quiz->posts()->doesntExist()) {
                QuizPost::factory()->create(['daily_quiz_id' => $quiz->id]);
            }
        });
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => DailyQuiz::STATUS_CLOSED,
            'posted_at' => now(),
            'closed_at' => now(),
        ])->afterCreating(function (DailyQuiz $quiz): void {
            if ($quiz->posts()->doesntExist()) {
                QuizPost::factory()->closed()->create(['daily_quiz_id' => $quiz->id]);
            }
        });
    }
}
