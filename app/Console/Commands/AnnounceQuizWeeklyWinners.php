<?php

namespace App\Console\Commands;

use App\Services\Quiz\QuizPoster;
use Illuminate\Console\Command;
use Throwable;

/**
 * End-of-week step of the daily quiz: crown this week's top players in the
 * group and reset the weekly counters so the new week starts level.
 */
class AnnounceQuizWeeklyWinners extends Command
{
    protected $signature = 'quiz:announce-weekly';

    protected $description = 'Announce the weekly quiz winners in the Telegram group and reset weekly points';

    public function handle(QuizPoster $poster): int
    {
        try {
            $poster->announceWeeklyWinners();
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Weekly announcement failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Weekly winners announced (or skipped — disabled / nobody scored).');

        return self::SUCCESS;
    }
}
