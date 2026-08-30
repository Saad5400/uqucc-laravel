<?php

namespace App\Console\Commands;

use App\Ai\OpinionPoll\OpinionPollAuthor;
use App\Models\OpinionPoll;
use App\Settings\OpinionPollSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Morning step of the opinion poll: write the day's poll well ahead of posting
 * time, so admins get a review window in the panel before `poll:post` sends it
 * to the groups.
 */
class GenerateOpinionPoll extends Command
{
    protected $signature = 'poll:generate {--date= : Day to generate for (Y-m-d), defaults to today}';

    protected $description = 'Generate the day\'s opinion poll for the configured Telegram groups';

    public function handle(OpinionPollSettings $settings, OpinionPollAuthor $author): int
    {
        if (! $settings->enabled) {
            $this->info('Opinion polls are disabled — skipping.');

            return self::SUCCESS;
        }

        $date = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : today();

        if (OpinionPoll::forDate($date) !== null) {
            $this->info("A poll already exists for {$date->toDateString()} — skipping.");

            return self::SUCCESS;
        }

        try {
            $poll = $author->generateForDate($date);
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Opinion poll generation failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Generated opinion poll #{$poll->id} for {$date->toDateString()}: {$poll->question}");

        return self::SUCCESS;
    }
}
