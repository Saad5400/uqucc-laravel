<?php

namespace App\Jobs;

use App\Ai\Quiz\QuizAuthor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * On-demand quiz generation from the panel's «توليد سؤال اليوم» button — the
 * authoring-tier call takes up to a few minutes, far too long for a web
 * request.
 */
class GenerateDailyQuizJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function handle(QuizAuthor $author): void
    {
        try {
            $author->generateForDate(today());
        } catch (Throwable $exception) {
            report($exception);

            Log::warning('On-demand quiz generation failed', [
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
