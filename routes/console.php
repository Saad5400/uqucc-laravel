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

Schedule::command('ai:prune-conversations')
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

Schedule::command('quiz:post')
    ->dailyAt('16:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quiz:announce-weekly')
    ->thursdays()
    ->at('21:00')
    ->withoutOverlapping()
    ->runInBackground();
