<?php

use App\Models\BotCommandStat;
use App\Models\TelegramInviteLink;
use App\Models\TelegramInviteLinkJoin;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

function inviteLink(int $creatorId, string $url, string $username, int $joins = 0): TelegramInviteLink
{
    return TelegramInviteLink::query()->create([
        'chat_id' => -100123,
        'chat_title' => 'مجموعة الحاسبات',
        'invite_link' => $url,
        'creator_telegram_user_id' => $creatorId,
        'creator_username' => $username,
        'creator_name' => $username,
        'member_limit' => 1,
        'joins_count' => $joins,
    ]);
}

function inviteJoin(TelegramInviteLink $link, int $joinerId, ?string $joinedAt = null): TelegramInviteLinkJoin
{
    return TelegramInviteLinkJoin::query()->create([
        'telegram_invite_link_id' => $link->id,
        'chat_id' => $link->chat_id,
        'invite_link' => $link->invite_link,
        'creator_telegram_user_id' => $link->creator_telegram_user_id,
        'joiner_telegram_user_id' => $joinerId,
        'joiner_username' => 'member'.$joinerId,
        'source' => 'invite_link',
        'joined_at' => $joinedAt ?? now(),
    ]);
}

it('redirects guests to the login page', function () {
    $this->get('/manage/invites')->assertRedirect(route('manage.login'));
});

it('ranks admins by the number of members who joined through their links', function () {
    $first = inviteLink(42, 'https://t.me/+one', 'admin1', joins: 2);
    $second = inviteLink(43, 'https://t.me/+two', 'admin2', joins: 1);

    inviteJoin($first, 901);
    inviteJoin($first, 902);
    inviteJoin($second, 903);

    $this->actingAs($this->admin)
        ->get('/manage/invites')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('manage/invites/Index')
            ->where('stats.joins', 3)
            ->where('stats.inviters', 2)
            ->where('stats.links', 2)
            ->where('leaderboard.0.username', 'admin1')
            ->where('leaderboard.0.joins', 2)
            ->where('leaderboard.1.username', 'admin2')
            ->where('leaderboard.1.joins', 1)
        );
});

it('lists admins whose links nobody used yet with a zero', function () {
    inviteLink(44, 'https://t.me/+unused', 'admin3');

    $this->actingAs($this->admin)
        ->get('/manage/invites')
        ->assertInertia(fn (Assert $page) => $page
            ->where('leaderboard.0.username', 'admin3')
            ->where('leaderboard.0.joins', 0)
            ->where('leaderboard.0.links', 1)
            ->where('stats.unusedLinks', 1)
        );
});

it('honours the period filter', function () {
    $link = inviteLink(42, 'https://t.me/+one', 'admin1', joins: 2);
    inviteJoin($link, 901, now()->subDays(10)->toDateTimeString());
    inviteJoin($link, 902, now()->subHours(2)->toDateTimeString());

    $this->actingAs($this->admin)
        ->get('/manage/invites?period=24h')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.period', '24h')
            ->where('stats.joins', 1)
        );
});

it('shows the historic رابط request counts alongside tracked joins', function () {
    BotCommandStat::query()->create([
        'command_name' => 'رابط',
        'telegram_user_id' => 42,
        'chat_type' => 'supergroup',
        'chat_id' => -100123,
        'count' => 18,
        'last_used_at' => now(),
    ]);

    $link = inviteLink(42, 'https://t.me/+one', 'admin1', joins: 1);
    inviteJoin($link, 901);

    $this->actingAs($this->admin)->get('/manage/invites')->assertOk();

    $this->actingAs($this->admin)
        ->get('/manage/invites', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => \Inertia\Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'manage/invites/Index',
            'X-Inertia-Partial-Data' => 'preTrackingRequests,recentJoins',
        ])
        ->assertOk()
        ->assertJsonPath('props.preTrackingRequests.0.telegram_user_id', '42')
        ->assertJsonPath('props.preTrackingRequests.0.username', 'admin1')
        ->assertJsonPath('props.preTrackingRequests.0.requests', 18)
        ->assertJsonPath('props.preTrackingRequests.0.before_tracking', 17)
        ->assertJsonPath('props.recentJoins.0.joiner_telegram_user_id', '901')
        ->assertJsonPath('props.recentJoins.0.inviter_telegram_user_id', '42');
});
