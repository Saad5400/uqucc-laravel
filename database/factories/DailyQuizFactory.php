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
            'question' => '<p dir="rtl">ما هي نتيجة 2 + 2 في النظام العشري؟</p>',
            'options' => ['3', '4', '5', '22'],
            'correct_option' => 1,
            'explanation' => 'الجمع الحسابي البسيط: 2 + 2 = 4.',
            'hint' => 'فكّر في أبسط عملية جمع.',
            'obvious_hint' => 'اجمع العددين كما هما.',
            'status' => DailyQuiz::STATUS_READY,
        ];
    }

    /**
     * A quiz whose HTML question carries a code block — the preamble, the
     * fenced code on its own directional line, and the question, exactly as the
     * image renderer expects.
     */
    public function withCode(): static
    {
        return $this->state(fn (): array => [
            'question' => '<p dir="rtl">في الكود التالي:</p>'
                .'<pre dir="ltr"><code>print(2 ** 3)</code></pre>'
                .'<p dir="rtl">ماذا يُطبع؟</p>',
        ]);
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
