<?php

namespace App\Jobs;

use App\Ai\Quiz\QuizAuthor;
use App\Models\QuizTopic;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * On-demand quiz generation from the panel's «توليد سؤال» button — the
 * authoring-tier call takes up to a few minutes, far too long for a web
 * request.
 *
 * `$date` (Y-m-d, defaults to today) is the day to generate for, so admins
 * can build a buffer of upcoming questions instead of only filling today.
 * `$topicId` forces a specific topic (null lets the author pick one); when
 * `$replace` is set, that day's `ready` question is regenerated in place —
 * without it {@see QuizAuthor::generateForDate()} refuses a day that already
 * has a question, so a generation never silently overwrites one.
 *
 * Runs on the dedicated "ai" queue alongside the corpus/authoring jobs: the
 * authoring-tier call is slow (up to a few minutes), and that worker is sized
 * for it (300s timeout, graceful stop) so a generation never blocks the quick
 * default-queue jobs behind it.
 */
class GenerateDailyQuizJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(
        public readonly ?string $date = null,
        public readonly ?int $topicId = null,
        public readonly bool $replace = false,
    ) {
        $this->onQueue('ai');
    }

    public function handle(QuizAuthor $author): void
    {
        try {
            $topic = $this->topicId !== null ? QuizTopic::find($this->topicId) : null;
            $date = $this->date !== null ? Carbon::parse($this->date)->startOfDay() : today();

            $author->generateForDate($date, $topic, $this->replace);
        } catch (Throwable $exception) {
            report($exception);

            Log::warning('On-demand quiz generation failed', [
                'date' => $this->date,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
