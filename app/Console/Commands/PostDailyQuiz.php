<?php

namespace App\Console\Commands;

use App\Ai\Quiz\QuizAuthor;
use App\Models\DailyQuiz;
use App\Services\Quiz\QuizPoster;
use App\Settings\QuizSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Afternoon step of the daily quiz: send today's question to the group as a
 * non-anonymous quiz poll. When the nightly generation failed to leave a
 * question, generation is retried inline so a transient AI failure doesn't
 * silently skip the day (and break everyone's streaks).
 */
class PostDailyQuiz extends Command
{
    protected $signature = 'quiz:post';

    protected $description = 'Post today\'s quiz question to the configured Telegram group';

    public function handle(QuizSettings $settings, QuizAuthor $author, QuizPoster $poster): int
    {
        if (! $settings->isConfigured()) {
            $this->info('Daily quiz is disabled or has no target group — skipping.');

            return self::SUCCESS;
        }

        $quiz = DailyQuiz::forDate(today());

        if ($quiz !== null && ! $quiz->isReady()) {
            $this->info('Today\'s quiz has already been posted — skipping.');

            return self::SUCCESS;
        }

        if ($quiz === null) {
            $this->warn('No quiz was generated for today — generating one now.');

            try {
                $quiz = $author->generateForDate(today());
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Fallback generation failed: {$exception->getMessage()}");

                return self::FAILURE;
            }
        }

        try {
            $quiz = $poster->post($quiz);
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Posting failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Posted quiz #{$quiz->id} to {$quiz->posts()->count()} chat(s).");

        return self::SUCCESS;
    }
}
