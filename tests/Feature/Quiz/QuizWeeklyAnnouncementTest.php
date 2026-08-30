<?php

use App\Models\QuizAnswer;
use App\Models\QuizPlayer;
use App\Models\TelegramTeam;
use App\Models\TelegramTeamMember;
use App\Services\Quiz\QuizPoster;
use App\Settings\QuizSettings;
use Tests\Fakes\FakeTelegramApi;

/**
 * The announcement runs Thursday evening: the outgoing week's last question
 * (Wednesday's) stopped taking votes when Thursday's went out at 16:00, so
 * the week it crowns — Thursday through Wednesday — is settled.
 */
beforeEach(function () {
    $this->travelTo('2026-08-27 21:00:00');

    $settings = app(QuizSettings::class);
    $settings->enabled = true;
    $settings->chat_ids = ['-100200300'];
    $settings->save();

    $this->fake = new FakeTelegramApi;
    $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $this->fake));
});

/** Points scored on the question of a given day. */
function scoredOn(QuizPlayer $player, string $quizDate, int $points): void
{
    QuizAnswer::factory()
        ->for($player, 'player')
        ->onQuizDate(Carbon\CarbonImmutable::parse($quizDate))
        ->create(['points' => $points]);
}

it('announces the top players of the week that just ended', function () {
    $first = QuizPlayer::factory()->create(['first_name' => 'أحمد', 'total_points' => 300]);
    $second = QuizPlayer::factory()->create(['first_name' => 'نورة', 'total_points' => 60]);
    $third = QuizPlayer::factory()->create(['first_name' => 'خالد', 'total_points' => 30]);
    $fourth = QuizPlayer::factory()->create(['first_name' => 'فهد', 'total_points' => 10]);

    scoredOn($first, '2026-08-20', 20);
    scoredOn($first, '2026-08-26', 30);
    scoredOn($second, '2026-08-24', 40);
    scoredOn($third, '2026-08-25', 30);
    scoredOn($fourth, '2026-08-26', 10);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1);

    $text = withoutBidi($this->fake->sentMessages[0]['text']);

    expect($this->fake->sentMessages[0]['chat_id'])->toBe(-100200300)
        ->and($text)->toContain('🥇 أحمد — 50 نقطة')
        ->toContain('🥈 نورة — 40 نقطة')
        ->toContain('🥉 خالد — 30 نقطة')
        ->toContain('4. فهد — 10 نقاط');

    expect($first->refresh()->total_points)->toBe(300);
});

it('leaves the new week\'s points out of the announcement, and keeps them', function () {
    $player = QuizPlayer::factory()->create(['first_name' => 'أحمد']);

    scoredOn($player, '2026-08-26', 30);
    // Today's question — the new week's first — was answered five hours ago.
    scoredOn($player, '2026-08-27', 12);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect(withoutBidi($this->fake->sentMessages[0]['text']))->toContain('أحمد — 30 نقطة');

    // Nothing is reset, so today's points still stand on the new week's board.
    expect(app(App\Services\Quiz\QuizLeaderboard::class)->weeklyPointsFor($player))->toBe(12);
});

it('ignores points older than the week it crowns', function () {
    $player = QuizPlayer::factory()->create(['first_name' => 'أحمد']);

    scoredOn($player, '2026-08-19', 90);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty();
});

it('caps the announcement at twenty players', function () {
    QuizPlayer::factory()->count(25)->sequence(fn ($sequence) => [
        'first_name' => 'لاعب'.($sequence->index + 1),
    ])->create()->each(fn (QuizPlayer $player, int $index) => scoredOn($player, '2026-08-26', 100 - $index));

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    $text = withoutBidi($this->fake->sentMessages[0]['text']);

    expect($text)->toContain('20. لاعب20')
        ->and($text)->not->toContain('لاعب21');
});

it('announces in every configured group', function () {
    $settings = app(QuizSettings::class);
    $settings->chat_ids = ['-100200300', '-100400500'];
    $settings->save();

    scoredOn(QuizPlayer::factory()->create(['first_name' => 'أحمد']), '2026-08-26', 50);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(2)
        ->and(collect($this->fake->sentMessages)->pluck('chat_id')->all())->toBe([-100200300, -100400500]);
});

it('stays silent when nobody scored this week', function () {
    QuizPlayer::factory()->create(['total_points' => 100]);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty();
});

it('stays silent while the feature is disabled', function () {
    $settings = app(QuizSettings::class);
    $settings->enabled = false;
    $settings->save();

    scoredOn(QuizPlayer::factory()->create(['first_name' => 'أحمد']), '2026-08-26', 50);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty();
});

it('escapes player names in the HTML announcement', function () {
    scoredOn(QuizPlayer::factory()->create(['first_name' => '<b>خبيث</b>']), '2026-08-26', 50);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect(withoutBidi($this->fake->sentMessages[0]['text']))->toContain('&lt;b&gt;خبيث&lt;/b&gt;');
});

/** A player of `team` who scored `points` on the given day's question. */
function teamScoredOn(TelegramTeam $team, int $telegramUserId, string $quizDate, int $points): void
{
    $player = QuizPlayer::factory()->create([
        'telegram_user_id' => $telegramUserId,
        'first_name' => 'لاعب'.$telegramUserId,
    ]);

    TelegramTeamMember::factory()->for($team, 'team')->create(['telegram_user_id' => $telegramUserId]);

    scoredOn($player, $quizDate, $points);
}

it('crowns the chat\'s own teams alongside the players', function () {
    $team = TelegramTeam::factory()->create(['chat_id' => -100200300, 'name' => 'العابدية']);

    teamScoredOn($team, 501, '2026-08-24', 30);
    teamScoredOn($team, 502, '2026-08-25', 30);
    teamScoredOn($team, 503, '2026-08-26', 30);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect(withoutBidi($this->fake->sentMessages[0]['text']))
        ->toContain('🛡️ <b>فرق الأسبوع</b>')
        ->toContain('🥇 العابدية — معدل 30 نقطة · شارك 3 من 3');
});

it('leaves the team block out where no team played at all', function () {
    $team = TelegramTeam::factory()->create(['chat_id' => -100200300, 'name' => 'العابدية']);
    TelegramTeamMember::factory()->count(3)->for($team, 'team')->create();

    scoredOn(QuizPlayer::factory()->create(['first_name' => 'وحيد']), '2026-08-26', 50);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect(withoutBidi($this->fake->sentMessages[0]['text']))->not->toContain('فرق الأسبوع');
});

it('names each group its own teams', function () {
    $settings = app(QuizSettings::class);
    $settings->chat_ids = ['-100200300', '-100400500'];
    $settings->save();

    $ours = TelegramTeam::factory()->create(['chat_id' => -100200300, 'name' => 'العابدية']);
    TelegramTeam::factory()->create(['chat_id' => -100400500, 'name' => 'الزاهر']);

    teamScoredOn($ours, 501, '2026-08-24', 30);
    teamScoredOn($ours, 502, '2026-08-25', 30);
    teamScoredOn($ours, 503, '2026-08-26', 30);

    $this->artisan('quiz:announce-weekly')->assertExitCode(0);

    expect(withoutBidi($this->fake->sentMessages[0]['text']))->toContain('العابدية')
        ->and(withoutBidi($this->fake->sentMessages[1]['text']))->not->toContain('العابدية');
});
