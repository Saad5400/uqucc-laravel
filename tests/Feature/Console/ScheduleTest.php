<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

it('runs quiz:post in the foreground, after every other task of its minute', function (): void {
    $events = collect(app(Schedule::class)->events());
    $quizPost = $events->filter(fn (Event $event): bool => Str::contains((string) $event->command, 'quiz:post'));

    expect($quizPost)->toHaveCount(1)
        ->and($quizPost->first()->runInBackground)->toBeFalse()
        ->and($quizPost->first()->expression)->toBe('* * * * *')
        // Last: schedule:run starts due tasks in order, so nothing waits on it.
        ->and($events->last())->toBe($quizPost->first());
});
