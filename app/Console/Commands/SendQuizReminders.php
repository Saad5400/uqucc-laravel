<?php

namespace App\Console\Commands;

use App\Services\Quiz\QuizReminder;
use Illuminate\Console\Command;

/**
 * Nudge the group to answer the live daily quiz. Scheduled once per phase
 * across the quiz's window (see {@see QuizReminder}); each call decides on
 * its own whether there is anything to remind about.
 */
class SendQuizReminders extends Command
{
    protected $signature = 'quiz:remind {phase : The reminder phase — one of opener, refloat, night, morning, hint, lastcall}';

    protected $description = 'Send a reminder to answer the live daily quiz';

    public function handle(QuizReminder $reminder): int
    {
        $phase = (string) $this->argument('phase');

        if (! in_array($phase, QuizReminder::PHASES, true)) {
            $this->error("Unknown reminder phase: {$phase}");

            return self::FAILURE;
        }

        $reminder->remind($phase);

        $this->info("Sent «{$phase}» quiz reminders (if any quiz is live).");

        return self::SUCCESS;
    }
}
