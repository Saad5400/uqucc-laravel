<?php

namespace App\Console\Commands;

use App\Models\OpinionPoll;
use App\Services\OpinionPoll\OpinionPollPoster;
use App\Services\OpinionPoll\OpinionPollSchedule;
use App\Settings\OpinionPollSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * The whole clock of «استطلاع الرأي», in one command run every minute: close
 * a poll whose day is up (announcing the result), then send today's poll when
 * its moment arrives.
 *
 * Both halves live here because the posting moment is a setting any single day
 * can override ({@see OpinionPollSchedule}) and the closing moment is a day
 * later, wherever that lands. Unlike the quiz there is no generation to fall
 * back on: the queue is written by hand, so a day with nothing queued is a
 * quiet day, not a failure.
 */
class PostOpinionPoll extends Command
{
    protected $signature = 'poll:post {--force : Post today\'s poll right now, even before its scheduled time or after it was already posted}';

    protected $description = 'Close the finished opinion poll and post today\'s to the configured Telegram groups';

    public function handle(OpinionPollSettings $settings, OpinionPollSchedule $schedule, OpinionPollPoster $poster): int
    {
        // Closing is deliberately not gated on the feature switch: a poll that
        // is already live in the groups must still be stopped and answered
        // even if an admin turned the feature off while it was running.
        $closed = $poster->closeElapsed();

        if ($closed > 0) {
            $this->info("Closed {$closed} finished opinion poll(s).");
        }

        if (! $settings->isConfigured()) {
            $this->info('Opinion polls are disabled or have no target group — skipping.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $poll = OpinionPoll::forDate(today());

        if ($poll === null) {
            $this->info('No opinion poll is queued for today — skipping.');

            return $force ? self::FAILURE : self::SUCCESS;
        }

        if ($force) {
            if (! $poll->isReady() && ! $poll->isPosted()) {
                $this->error('Today\'s poll is already closed — it cannot be posted again.');

                return self::FAILURE;
            }
        } else {
            if (! $poll->isReady()) {
                $this->info('Today\'s opinion poll has already been posted — skipping.');

                return self::SUCCESS;
            }

            if (! $schedule->isTodayDue()) {
                return self::SUCCESS;
            }
        }

        $reposting = $poll->isPosted();

        try {
            $poll = $poster->post($poll, $force);
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Posting failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $verb = $reposting ? 'Re-posted' : 'Posted';
        $this->info("{$verb} opinion poll #{$poll->id} to {$poll->posts()->open()->count()} chat(s).");

        return self::SUCCESS;
    }
}
