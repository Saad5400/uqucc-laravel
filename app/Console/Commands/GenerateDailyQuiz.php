<?php

namespace App\Console\Commands;

use App\Ai\Quiz\QuizAuthor;
use App\Models\DailyQuiz;
use App\Settings\QuizSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Nightly step of the daily quiz: generate the question for the day ahead of
 * posting time, so admins get a review/edit window in the panel before
 * `quiz:post` sends it to the group.
 */
class GenerateDailyQuiz extends Command
{
    protected $signature = 'quiz:generate {--date= : Day to generate for (Y-m-d), defaults to today}';

    protected $description = 'Generate the daily quiz question for the configured Telegram group';

    public function handle(QuizSettings $settings, QuizAuthor $author): int
    {
        if (! $settings->enabled) {
            $this->info('Daily quiz is disabled — skipping.');

            return self::SUCCESS;
        }

        $date = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : today();

        if (DailyQuiz::forDate($date) !== null) {
            $this->info("A quiz already exists for {$date->toDateString()} — skipping.");

            return self::SUCCESS;
        }

        try {
            $quiz = $author->generateForDate($date);
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Quiz generation failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Generated quiz #{$quiz->id} for {$date->toDateString()}: {$quiz->question}");

        return self::SUCCESS;
    }
}
