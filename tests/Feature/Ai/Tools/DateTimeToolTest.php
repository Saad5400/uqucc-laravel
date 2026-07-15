<?php

use App\Ai\Tools\DateTimeTool;
use App\Settings\AiSettings;
use Carbon\CarbonImmutable;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->save();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('reports the current date in both calendars in the app timezone', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 14:30', 'Asia/Riyadh'));

    $reply = (string) app(DateTimeTool::class)->handle(new Request(['operation' => 'now']));

    expect($reply)->toContain('2026-07-15 14:30')
        ->toContain('Asia/Riyadh')
        ->toContain('Wednesday')
        ->toContain('الهجري');
});

it('defaults to the now operation', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 09:00', 'Asia/Riyadh'));

    $reply = (string) app(DateTimeTool::class)->handle(new Request([]));

    expect($reply)->toContain('2026-07-15 09:00');
});

it('adds a duration to a datetime', function () {
    $reply = (string) app(DateTimeTool::class)->handle(new Request([
        'operation' => 'add',
        'datetime' => '2026-07-15 14:30',
        'amount' => 3,
        'unit' => 'days',
    ]));

    expect($reply)->toContain('2026-07-18 14:30');
});

it('subtracts a duration from a datetime', function () {
    $reply = (string) app(DateTimeTool::class)->handle(new Request([
        'operation' => 'subtract',
        'datetime' => '2026-07-15 14:30',
        'amount' => 2,
        'unit' => 'hours',
    ]));

    expect($reply)->toContain('2026-07-15 12:30');
});

it('computes the difference between two datetimes', function () {
    $reply = (string) app(DateTimeTool::class)->handle(new Request([
        'operation' => 'difference',
        'datetime' => '2026-07-15',
        'datetime2' => '2026-07-25',
    ]));

    expect($reply)->toContain('10')
        ->toContain('later than');
});

it('rejects an invalid unit for add', function () {
    $reply = (string) app(DateTimeTool::class)->handle(new Request([
        'operation' => 'add',
        'datetime' => 'now',
        'amount' => 3,
        'unit' => 'fortnights',
    ]));

    expect($reply)->toContain('unit');
});

it('rejects an unparseable datetime', function () {
    $reply = (string) app(DateTimeTool::class)->handle(new Request([
        'operation' => 'add',
        'datetime' => 'not a date at all',
        'amount' => 3,
        'unit' => 'days',
    ]));

    expect($reply)->toContain('Could not understand');
});

it('reports an unknown operation', function () {
    $reply = (string) app(DateTimeTool::class)->handle(new Request(['operation' => 'multiply']));

    expect($reply)->toContain('Unknown operation');
});

it('returns a disabled message when the master ai kill switch is off', function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->save();

    $reply = (string) app(DateTimeTool::class)->handle(new Request(['operation' => 'now']));

    expect($reply)->toContain('معطلة');
});
