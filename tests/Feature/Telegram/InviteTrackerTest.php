<?php

use App\Models\TelegramInviteLink;
use App\Models\TelegramInviteLinkJoin;
use App\Services\Telegram\InviteTracker;

function chatMemberUpdate(array $overrides = []): array
{
    return array_replace_recursive([
        'chat' => ['id' => -100123, 'title' => 'مجموعة الحاسبات', 'type' => 'supergroup'],
        'from' => ['id' => 555, 'first_name' => 'المنضم'],
        'date' => 1_756_000_000,
        'old_chat_member' => ['user' => ['id' => 555], 'status' => 'left'],
        'new_chat_member' => ['user' => ['id' => 555, 'username' => 'joiner', 'first_name' => 'المنضم'], 'status' => 'member'],
        'invite_link' => [
            'invite_link' => 'https://t.me/+abc123',
            'creator' => ['id' => 42, 'username' => 'admin1'],
            'member_limit' => 1,
        ],
    ], $overrides);
}

it('attributes a join to the admin who requested the link', function () {
    $tracker = new InviteTracker;

    $link = $tracker->recordLink(
        chatId: -100123,
        chatTitle: 'مجموعة الحاسبات',
        inviteLink: 'https://t.me/+abc123',
        creator: ['id' => 42, 'username' => 'admin1', 'first_name' => 'أحمد'],
        linkName: 'دعوة admin1',
        memberLimit: 1,
    );

    $join = $tracker->recordChatMemberUpdate(chatMemberUpdate());

    expect($join)->not->toBeNull()
        ->and($join->creator_telegram_user_id)->toBe(42)
        ->and($join->joiner_telegram_user_id)->toBe(555)
        ->and($join->joiner_username)->toBe('joiner')
        ->and($join->source)->toBe('invite_link')
        ->and($join->telegram_invite_link_id)->toBe($link->id)
        ->and($link->fresh()->joins_count)->toBe(1);
});

it('ignores membership changes that are not joins', function () {
    $tracker = new InviteTracker;

    $leaving = $tracker->recordChatMemberUpdate(chatMemberUpdate([
        'old_chat_member' => ['status' => 'member'],
        'new_chat_member' => ['status' => 'left'],
    ]));

    $promotion = $tracker->recordChatMemberUpdate(chatMemberUpdate([
        'old_chat_member' => ['status' => 'member'],
        'new_chat_member' => ['status' => 'administrator'],
    ]));

    expect($leaving)->toBeNull()
        ->and($promotion)->toBeNull()
        ->and(TelegramInviteLinkJoin::count())->toBe(0);
});

it('records a join through a link an admin made by hand, using the link creator', function () {
    $join = (new InviteTracker)->recordChatMemberUpdate(chatMemberUpdate([
        'invite_link' => ['invite_link' => 'https://t.me/+manual', 'creator' => ['id' => 99, 'is_bot' => false]],
    ]));

    expect($join->creator_telegram_user_id)->toBe(99)
        ->and($join->telegram_invite_link_id)->toBeNull()
        ->and(TelegramInviteLink::count())->toBe(0);
});

it('never credits the bot itself for an untracked link it created', function () {
    $join = (new InviteTracker)->recordChatMemberUpdate(chatMemberUpdate([
        'invite_link' => ['invite_link' => 'https://t.me/+older', 'creator' => ['id' => 7912800477, 'is_bot' => true]],
    ]));

    expect($join->creator_telegram_user_id)->toBeNull()
        ->and($join->source)->toBe('invite_link');
});

it('stores the join time in the application timezone', function () {
    $join = (new InviteTracker)->recordChatMemberUpdate(chatMemberUpdate(['date' => 1_756_000_000]));

    expect($join->joined_at->timestamp)->toBe(1_756_000_000)
        ->and($join->joined_at->format('Y-m-d H:i'))
        ->toBe(\Illuminate\Support\Carbon::createFromTimestamp(1_756_000_000, config('app.timezone'))->format('Y-m-d H:i'));
});

it('records joins with no invite link and does not attribute them', function () {
    $selfJoin = (new InviteTracker)->recordChatMemberUpdate(chatMemberUpdate(['invite_link' => null]));

    expect($selfJoin->source)->toBe('self')
        ->and($selfJoin->creator_telegram_user_id)->toBeNull();

    $added = (new InviteTracker)->recordChatMemberUpdate(chatMemberUpdate([
        'invite_link' => null,
        'from' => ['id' => 42],
        'new_chat_member' => ['user' => ['id' => 777]],
        'date' => 1_756_000_100,
    ]));

    expect($added->source)->toBe('added_by_admin');
});

it('does not double count a replayed update', function () {
    $tracker = new InviteTracker;
    $tracker->recordLink(-100123, 'مجموعة الحاسبات', 'https://t.me/+abc123', ['id' => 42], null, 1);

    $tracker->recordChatMemberUpdate(chatMemberUpdate());
    $tracker->recordChatMemberUpdate(chatMemberUpdate());

    expect(TelegramInviteLinkJoin::count())->toBe(1)
        ->and(TelegramInviteLink::first()->joins_count)->toBe(1);
});
