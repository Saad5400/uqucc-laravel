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
