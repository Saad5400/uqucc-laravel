<?php

namespace App\Console\Commands;

use App\Ai\Quiz\QuizAuthor;
use App\Models\DailyQuiz;
use App\Services\Quiz\QuizPoster;
use App\Services\Quiz\QuizSchedule;
use App\Settings\QuizSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Afternoon step of the daily quiz: send today's question to the group as a
 * non-anonymous quiz poll. When the nightly generation failed to leave a
 * question, generation is retried inline so a transient AI failure doesn't
 * silently skip the day (and break everyone's streaks).
 *
 * The command is scheduled every minute and decides for itself whether the
 * posting moment has come, because that moment is a setting any single day can
 * override — see {@see QuizSchedule}. `--force` ignores the clock and posts
 * right now, including re-posting a question whose poll was deleted from the
 * group by mistake.
 */
class PostDailyQuiz extends Command
{
    protected $signature = 'quiz:post {--force : Post today\'s question right now, even before its scheduled time or after it was already posted}';

    protected $description = 'Post today\'s quiz question to the configured Telegram group';

    /**
     * How long to wait before retrying the inline fallback generation. The
     * command runs every minute; a failing authoring model must not be asked
     * that often.
     *
     * Kept short because this window opens at posting time and every minute
     * of it is a minute the group is waiting: an attempt already occupies
     * several of these minutes on its own (the authoring tier reasons for one
     * to two minutes, twice), so this is the pause *after* a failure, not the
     * spacing between calls. It closes for the day the moment a question
     * exists.
     */
    private const FALLBACK_RETRY_MINUTES = 5;

    public function handle(QuizSettings $settings, QuizSchedule $schedule, QuizAuthor $author, QuizPoster $poster): int
    {
        if (! $settings->isConfigured()) {
            $this->info('Daily quiz is disabled or has no target group — skipping.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $quiz = DailyQuiz::forDate(today());

        if ($force) {
            if ($quiz === null) {
                $this->error('There is no question for today — generate one first.');

                return self::FAILURE;
            }

            if (! $quiz->isReady() && ! $quiz->isPosted()) {
                $this->error('Today\'s question is already closed — it cannot be posted again.');

                return self::FAILURE;
            }
        } else {
            if ($quiz !== null && ! $quiz->isReady()) {
                $this->info('Today\'s quiz has already been posted — skipping.');

                return self::SUCCESS;
            }

            if (! $schedule->isTodayDue()) {
                return self::SUCCESS;
            }

            if ($quiz === null) {
                if (! $this->fallbackAttemptable()) {
                    $this->info('Fallback generation was tried recently — waiting before the next attempt.');

                    return self::SUCCESS;
                }

                $quiz = $this->generateFallback($author);

                if ($quiz === null) {
                    return self::FAILURE;
                }
            }
        }

        $reposting = $quiz->isPosted();

        try {
            $quiz = $poster->post($quiz, $force);
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Posting failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $verb = $reposting ? 'Re-posted' : 'Posted';
        $this->info("{$verb} quiz #{$quiz->id} to {$quiz->posts()->open()->count()} chat(s).");

        return self::SUCCESS;
    }

    /**
     * Whether an inline generation attempt is allowed right now — throttled so
     * the per-minute schedule can't hammer the authoring model.
     */
    private function fallbackAttemptable(): bool
    {
        return Cache::add(
            'quiz:post-fallback:'.today()->toDateString(),
            true,
            now()->addMinutes(self::FALLBACK_RETRY_MINUTES),
        );
    }

    private function generateFallback(QuizAuthor $author): ?DailyQuiz
    {
        $this->warn('No quiz was generated for today — generating one now.');

        try {
            return $author->generateForDate(today());
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Fallback generation failed: {$exception->getMessage()}");

            return null;
        }
    }
}
