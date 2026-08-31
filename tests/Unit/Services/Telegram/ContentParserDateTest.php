<?php

use App\Services\Telegram\ContentParser;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('renders the reward date in the Umm al-Qura calendar', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-31 12:00', 'Asia/Riyadh'));

    $rendered = new ContentParser()->processDates('إيداع المكافأة يكون في {*-*-27|جمعة:-1|سبت:+1}');

    expect($rendered)->toContain('الأحد [1448-04-16هـ] [2026-09-27مـ]')
        ->and($rendered)->not->toContain('1448-04-14');
});

it('keeps the Hijri date aligned with the weekday it shifts to', function () {
    // 2026-11-27 is a Friday, so the rule moves the deposit to Thursday the 26th.
    Carbon::setTestNow(Carbon::parse('2026-11-01 12:00', 'Asia/Riyadh'));

    expect(new ContentParser()->processDates('{*-*-27|جمعة:-1|سبت:+1}'))
        ->toContain('الخميس [1448-06-16هـ] [2026-11-26مـ]');
});
