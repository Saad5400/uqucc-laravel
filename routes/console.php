<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Weekly cleanup of orphaned screenshot files
Schedule::command('app:cleanup-caches --screenshots-days=14')
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->withoutOverlapping();
