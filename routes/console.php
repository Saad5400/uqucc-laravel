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

// Both entries carry an explicit one-hour mutex expiry rather than the 24-hour
// default: they share one mutex (it is keyed by the command, not the time), a
// generation runs for minutes at most, and a run killed mid-flight must not
// leave a lock that silently swallows the 11:00 safety net below — or
// tomorrow's 05:00 run.
Schedule::command('quiz:generate')
    ->dailyAt('05:00')
    ->withoutOverlapping(60)
    ->runInBackground();

// Safety net for the 05:00 run, which is one command invocation and can still
// exhaust its attempts on a bad night — the authoring tier grazes its 180s
// timeout. Without this, a question-less day is discovered only by `quiz:post`
// at posting time, and the group gets its question late. A no-op on every
// normal day: `quiz:generate` skips a date that already has a question, and
// this still lands hours before posting so admins keep their review window.
Schedule::command('quiz:generate')
    ->dailyAt('11:00')
    ->withoutOverlapping(60)
    ->runInBackground();

// Runs every minute and posts only when the configured moment arrives — the
// posting time is a setting, and a single day can be rescheduled on its own,
// so it cannot be pinned to one hour here (see App\Services\Quiz\QuizSchedule).
Schedule::command('quiz:post')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// The opinion poll's own authoring pass, half an hour after the quiz's so the
// two never contend for the authoring model. A day that already has a poll —
// hand-written or generated — is skipped, so this only ever fills gaps.
Schedule::command('poll:generate')
    ->dailyAt('05:30')
    ->withoutOverlapping(60)
    ->runInBackground();

// Safety net for the 05:30 run, on the same reasoning as the quiz's: one
// command invocation can exhaust its attempts on a bad night, and this still
// lands hours before posting so admins keep their review window. A no-op on
// every normal day.
Schedule::command('poll:generate')
    ->dailyAt('11:30')
    ->withoutOverlapping(60)
    ->runInBackground();

// The whole opinion-poll clock in one entry: it closes a poll whose day is up
// (announcing the result) and posts today's when its moment arrives, authoring
// one on the spot if the two generation passes above left the day empty. Runs
// every minute for the same reason `quiz:post` does — the posting time is a
// setting a single day can override (see
// App\Services\OpinionPoll\OpinionPollSchedule).
Schedule::command('poll:post')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// Thursday evening, after the new week's first question went out at 16:00 and
// the outgoing week's last one (Wednesday's) stopped taking votes with it.
// The command only sends a message — the weekly board is summed from the
// answer trail per quiz day (App\Services\Quiz\QuizLeaderboard), so a run
// that fires late, twice, or not at all costs nobody their points.
Schedule::command('quiz:announce-weekly')
    ->thursdays()
    ->at('21:00')
    ->withoutOverlapping()
    ->runInBackground();

// The twelve nudges spread across the quiz's 16:00 → 16:00 window, in order.
// Each phase has its own voice (see App\Services\Quiz\QuizReminder) and skips
// itself when it has nothing to say; the whole family is behind the
// «reminders_enabled» switch.
Schedule::command('quiz:remind kickoff')
    ->dailyAt('17:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:remind opener')
    ->dailyAt('18:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:remind topic')
    ->dailyAt('20:00')
    ->withoutOverlapping()
    ->runInBackground();

// Skips Thursday (day 4) so it never collides with the 21:00 weekly-winners
// announcement.
Schedule::command('quiz:remind refloat')
    ->days([0, 1, 2, 3, 5, 6])
    ->at('21:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:remind momentum')
    ->dailyAt('22:15')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:remind night')
    ->dailyAt('23:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:remind latenight')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:remind morning')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:remind trap')
    ->dailyAt('11:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:remind hint')
    ->dailyAt('12:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:remind lastcall')
    ->dailyAt('14:30')
    ->withoutOverlapping()
    ->runInBackground();

// The buzzer, minutes before the live quiz closes at 16:00.
Schedule::command('quiz:remind closing')
    ->dailyAt('15:40')
    ->withoutOverlapping()
    ->runInBackground();
