<?php

use App\Models\QuizAnswer;
use App\Models\QuizPlayer;
use App\Models\TelegramTeam;
use App\Models\TelegramTeamMember;
use App\Services\Quiz\QuizTeamLeaderboard;
use App\Services\Quiz\QuizTeamStanding;

const TEAM_BOARD_CHAT_ID = -100200300;

/** A member of `team` who scored `points` on today's question. */
function playerFor(TelegramTeam $team, int $telegramUserId, int $points): void
{
    $player = QuizPlayer::factory()->create(['telegram_user_id' => $telegramUserId]);

    QuizAnswer::factory()->for($player, 'player')->onQuizDate(today())->create(['points' => $points]);

    TelegramTeamMember::factory()->for($team, 'team')->create(['telegram_user_id' => $telegramUserId]);
}

function teamBoard(): array
{
    return app(QuizTeamLeaderboard::class)->forChat(TEAM_BOARD_CHAT_ID, today()->subDays(6));
}

function boardTeam(string $name): TelegramTeam
{
    return TelegramTeam::factory()->create(['chat_id' => TEAM_BOARD_CHAT_ID, 'name' => $name]);
}

it('breaks a tie on the average by how many of the team played', function () {
    $few = boardTeam('الزاهر');
    $many = boardTeam('العابدية');

    playerFor($few, 201, 20);
    playerFor($few, 202, 20);
    playerFor($few, 203, 20);

    foreach ([301, 302, 303, 304, 305] as $id) {
        playerFor($many, $id, 20);
    }

    expect(array_map(fn (QuizTeamStanding $standing): string => $standing->team->name, teamBoard()))
        ->toBe(['العابدية', 'الزاهر']);
});

it('counts a player for every team they belong to', function () {
    $branch = boardTeam('العابدية');
    $major = boardTeam('علوم الحاسب');

    $player = QuizPlayer::factory()->create(['telegram_user_id' => 201]);
    QuizAnswer::factory()->for($player, 'player')->onQuizDate(today())->create(['points' => 30]);

    TelegramTeamMember::factory()->for($branch, 'team')->create(['telegram_user_id' => 201]);
    TelegramTeamMember::factory()->for($major, 'team')->create(['telegram_user_id' => 201]);

    expect(teamBoard())->toHaveCount(2)
        ->and(teamBoard()[0]->points)->toBe(30);
});

it('leaves a quiet team off the board entirely', function () {
    $playing = boardTeam('الزاهر');
    $quiet = boardTeam('العزيزية');

    playerFor($playing, 201, 10);
    TelegramTeamMember::factory()->count(3)->for($quiet, 'team')->create();

    expect(array_map(fn (QuizTeamStanding $standing): string => $standing->team->name, teamBoard()))
        ->toBe(['الزاهر']);
});

it('never counts another chat\'s teams', function () {
    $ours = boardTeam('الزاهر');
    $theirs = TelegramTeam::factory()->create(['chat_id' => -100999888, 'name' => 'الزاهر']);

    playerFor($ours, 201, 10);
    playerFor($theirs, 301, 500);

    expect(teamBoard())->toHaveCount(1)
        ->and(teamBoard()[0]->points)->toBe(10);
});

it('ignores answers from outside the period', function () {
    $team = boardTeam('الزاهر');

    $player = QuizPlayer::factory()->create(['telegram_user_id' => 201]);
    QuizAnswer::factory()->for($player, 'player')->onQuizDate(today()->subMonth())->create(['points' => 500]);
    TelegramTeamMember::factory()->for($team, 'team')->create(['telegram_user_id' => 201]);

    expect(teamBoard())->toBeEmpty();
});

it('averages over the members who played, rounded', function () {
    $team = boardTeam('الزاهر');

    playerFor($team, 201, 10);
    playerFor($team, 202, 11);
    // Two more on the roster who sat the week out: they are neither in the
    // numerator nor the denominator.
    TelegramTeamMember::factory()->count(2)->for($team, 'team')->create();

    expect(teamBoard()[0]->average())->toBe(11)
        ->and(teamBoard()[0]->activeMembers)->toBe(2)
        ->and(teamBoard()[0]->members)->toBe(4);
});
