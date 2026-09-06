<?php

use App\Models\TelegramInviteLink;
use App\Services\Telegram\Handlers\InviteLinkHandler;
use Illuminate\Support\Facades\Bus;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

function inviteRequest(array $overrides = []): Message
{
    return new Message(array_replace_recursive([
        'message_id' => 77,
        'from' => ['id' => 42, 'is_bot' => false, 'first_name' => 'أحمد', 'username' => 'admin1'],
        'chat' => ['id' => -100123, 'type' => 'supergroup', 'title' => 'مجموعة الحاسبات'],
        'text' => 'رابط',
    ], $overrides));
}

function handleInviteRequest(FakeTelegramApi $api, array $overrides = []): void
{
    Bus::fake();

    $api->chatMemberStatuses[42] = 'creator';

    (new InviteLinkHandler($api))->handle(inviteRequest($overrides));
}

it('creates a one-time link that expires within 24 hours', function () {
    $this->freezeTime();

    $api = new FakeTelegramApi;

    handleInviteRequest($api);

    expect($api->createdInviteLinks)->toHaveCount(1);

    $params = $api->createdInviteLinks[0];

    expect($params['member_limit'])->toBe(1)
        ->and($params['expire_date'])->toBe(now()->addHours(24)->getTimestamp());
});

it('tells the requester when the link stops working', function () {
    $api = new FakeTelegramApi;

    handleInviteRequest($api);

    $privateMessage = collect($api->sentMessages)->firstWhere('chat_id', 42);

    expect($privateMessage['text'])->toContain('24 ساعة')
        ->and($privateMessage['text'])->toContain('لشخص واحد فقط');
});

it('records the expiry alongside the link', function () {
    $this->freezeTime();

    $api = new FakeTelegramApi;

    handleInviteRequest($api);

    $link = TelegramInviteLink::query()->sole();

    expect($link->expires_at->timestamp)->toBe(now()->addHours(24)->getTimestamp())
        ->and($link->member_limit)->toBe(1)
        ->and($link->creator_telegram_user_id)->toBe(42);
});
