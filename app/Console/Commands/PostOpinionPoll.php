<?php

namespace App\Console\Commands;

use App\Ai\OpinionPoll\OpinionPollAuthor;
use App\Models\OpinionPoll;
use App\Services\OpinionPoll\OpinionPollPoster;
use App\Services\OpinionPoll\OpinionPollSchedule;
use App\Settings\OpinionPollSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The whole clock of «استطلاع الرأي», in one command run every minute: close
 * a poll whose day is up (announcing the result), then send today's poll when
 * its moment arrives.
 *
 * Both halves live here because the posting moment is a setting any single day
 * can override ({@see OpinionPollSchedule}) and the closing moment is a day
 * later, wherever that lands.
 *
 * When the moment arrives on a day with nothing queued, generation is retried
 * inline ({@see OpinionPollAuthor}) rather than letting the ritual skip a day
 * — the same safety net the quiz has. With generation unavailable (AI off, no
 * key, budget spent) the day simply passes in silence: a poll is a daily habit,
 * not an obligation, and no message is better than a broken one.
 */
class PostOpinionPoll extends Command
{
    protected $signature = 'poll:post {--force : Post today\'s poll right now, even before its scheduled time or after it was already posted}';

    protected $description = 'Close the finished opinion poll and post today\'s to the configured Telegram groups';

    /**
     * How long to wait before retrying the inline fallback generation. The
     * command runs every minute; an authoring model that is failing must not
     * be asked that often. Kept short because this window opens at posting
     * time and every minute of it is a minute the group is waiting.
     */
    private const FALLBACK_RETRY_MINUTES = 5;

    public function handle(
        OpinionPollSettings $settings,
        OpinionPollSchedule $schedule,
        OpinionPollAuthor $author,
        OpinionPollPoster $poster,
    ): int {
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

        if ($force) {
            if ($poll === null) {
                $this->error('There is no poll for today — generate one first.');

                return self::FAILURE;
            }

            if (! $poll->isReady() && ! $poll->isPosted()) {
                $this->error('Today\'s poll is already closed — it cannot be posted again.');

                return self::FAILURE;
            }
        } else {
            if ($poll !== null && ! $poll->isReady()) {
                $this->info('Today\'s opinion poll has already been posted — skipping.');

                return self::SUCCESS;
            }

            if (! $schedule->isTodayDue()) {
                return self::SUCCESS;
            }

            if ($poll === null) {
                if ($author->disabledReason() !== null) {
                    $this->info('No opinion poll is queued for today and generation is unavailable — skipping.');

                    return self::SUCCESS;
                }

                if (! $this->fallbackAttemptable()) {
                    $this->info('Fallback generation was tried recently — waiting before the next attempt.');

                    return self::SUCCESS;
                }

                $poll = $this->generateFallback($author);

                if ($poll === null) {
                    return self::FAILURE;
                }
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

    /**
     * Whether an inline generation attempt is allowed right now — throttled so
     * the per-minute schedule can't hammer the authoring model.
     */
    private function fallbackAttemptable(): bool
    {
        return Cache::add(
            'poll:post-fallback:'.today()->toDateString(),
            true,
            now()->addMinutes(self::FALLBACK_RETRY_MINUTES),
        );
    }

    private function generateFallback(OpinionPollAuthor $author): ?OpinionPoll
    {
        $this->warn('No opinion poll was queued for today — generating one now.');

        try {
            return $author->generateForDate(today());
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Fallback generation failed: {$exception->getMessage()}");

            return null;
        }
    }
}
