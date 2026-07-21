<?php

use App\Models\QuizPlayer;
use App\Services\Quiz\QuizPoster;
use App\Settings\QuizSettings;
use Tests\Fakes\FakeTelegramApi;

beforeEach(function () {
    $settings = app(QuizSettings::class);
    $settings->enabled = true;
    $settings->chat_id = '-100200300';
    $settings->save();

    $this->fake = new FakeTelegramApi;
    $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $this->fake));
});

it('announces the weekly top three and resets weekly points only', function () {
    $first = QuizPlayer::factory()->create(['first_name' => 'أحمد', 'weekly_points' => 50, 'total_points' => 300]);
    $second = QuizPlayer::factory()->create(['first_name' => 'نورة', 'weekly_points' => 40, 'total_points' => 60]);
    $third = QuizPlayer::factory()->create(['first_name' => 'خالد', 'weekly_points' => 30, 'total_points' => 30]);
    $fourth = QuizPlayer::factory()->create(['first_name' => 'فهد', 'weekly_points' => 10, 'total_points' => 10]);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1);

    $text = $this->fake->sentMessages[0]['text'];

    expect($this->fake->sentMessages[0]['chat_id'])->toBe(-100200300)
        ->and($text)->toContain('🥇 أحمد — 50 نقطة')
        ->toContain('🥈 نورة — 40 نقطة')
        ->toContain('🥉 خالد — 30 نقطة')
        ->and($text)->not->toContain('فهد');

    expect($first->refresh()->weekly_points)->toBe(0)
        ->and($first->total_points)->toBe(300)
        ->and($second->refresh()->weekly_points)->toBe(0)
        ->and($third->refresh()->weekly_points)->toBe(0)
        ->and($fourth->refresh()->weekly_points)->toBe(0);
});

it('stays silent when nobody scored this week', function () {
    QuizPlayer::factory()->create(['weekly_points' => 0, 'total_points' => 100]);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty();
});

it('stays silent while the feature is disabled', function () {
    $settings = app(QuizSettings::class);
    $settings->enabled = false;
    $settings->save();

    QuizPlayer::factory()->create(['weekly_points' => 50]);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty()
        ->and(QuizPlayer::query()->first()->weekly_points)->toBe(50);
});

it('escapes player names in the HTML announcement', function () {
    QuizPlayer::factory()->create(['first_name' => '<b>خبيث</b>', 'weekly_points' => 50]);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect($this->fake->sentMessages[0]['text'])->toContain('&lt;b&gt;خبيث&lt;/b&gt;');
});
