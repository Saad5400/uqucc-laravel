<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('storage:cleanup --screenshots')
    ->weekly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('sitemap:generate')
    ->daily()
    ->withoutOverlapping()
    ->runInBackground();

// ai-kit's pruner deletes conversations (and messages) idle beyond the
// window (ai-kit.conversations.retention_days); the
// App\Listeners\PruneChatAttachments listener cascades chat attachments
// (rows + stored files) off its ConversationsPruning event.
Schedule::command('ai-kit:prune-conversations')
    ->daily()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('ai:ingest-pages')
    ->weekly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:generate')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->runInBackground();

// Runs every minute and posts only when the configured moment arrives — the
// posting time is a setting, and a single day can be rescheduled on its own,
// so it cannot be pinned to one hour here (see App\Services\Quiz\QuizSchedule).
Schedule::command('quiz:post')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('quiz:announce-weekly')
    ->thursdays()
    ->at('21:00')
    ->withoutOverlapping()
    ->runInBackground();

// The six nudges spread across the quiz's 16:00 → 16:00 window, in order.
// Each phase has its own voice (see App\Services\Quiz\QuizReminder) and skips
// itself when it has nothing to say; the whole family is behind the
// «reminders_enabled» switch.
Schedule::command('quiz:remind opener')
    ->dailyAt('18:30')
    ->withoutOverlapping()
    ->runInBackground();

// Skips Thursday (day 4) so it never collides with the 21:00 weekly-winners
// announcement.
Schedule::command('quiz:remind refloat')
    ->days([0, 1, 2, 3, 5, 6])
    ->at('21:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:remind night')
    ->dailyAt('23:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:remind morning')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:remind hint')
    ->dailyAt('12:30')
    ->withoutOverlapping()
    ->runInBackground();

// Last call before the live quiz closes at 16:00.
Schedule::command('quiz:remind lastcall')
    ->dailyAt('14:30')
    ->withoutOverlapping()
    ->runInBackground();
