<?php

namespace App\Jobs;

use App\Ai\OpinionPoll\OpinionPollAuthor;
use App\Ai\OpinionPoll\OpinionPollTheme;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * On-demand opinion-poll generation from the panel's «توليد بالذكاء
 * الاصطناعي» button — the authoring-tier call is far too slow for a web
 * request.
 *
 * `$date` (Y-m-d, defaults to today) is the day to generate for, so admins can
 * fill a week in one sitting. `$theme` forces an angle (null lets the rotation
 * pick); when `$replace` is set, that day's `ready` poll is regenerated in
 * place — without it {@see OpinionPollAuthor::generateForDate()} refuses a day
 * that already has one, so a generation never silently overwrites a poll.
 *
 * Runs on the dedicated "ai" queue alongside the quiz and corpus jobs, which
 * is sized for calls that take minutes.
 */
class GenerateOpinionPollJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(
        public readonly ?string $date = null,
        public readonly ?string $theme = null,
        public readonly bool $replace = false,
    ) {
        $this->onQueue('ai');
    }

    public function handle(OpinionPollAuthor $author): void
    {
        try {
            $theme = $this->theme !== null ? OpinionPollTheme::tryFrom($this->theme) : null;
            $date = $this->date !== null ? Carbon::parse($this->date)->startOfDay() : today();

            $author->generateForDate($date, $theme, $this->replace);
        } catch (Throwable $exception) {
            report($exception);

            Log::warning('On-demand opinion poll generation failed', [
                'date' => $this->date,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
