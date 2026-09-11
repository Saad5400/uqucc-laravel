<?php

use App\Services\Telegram\Handlers\InfoHandler;
use App\Services\Telegram\Handlers\PrivateForwardHandler;
use Illuminate\Support\Facades\Bus;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

beforeEach(fn () => Bus::fake());

function requesterNoticeMessage(string $text, array $overrides = []): Message
{
    return new Message(array_replace_recursive([
        'message_id' => 91,
        'from' => ['id' => 404, 'is_bot' => false, 'first_name' => 'سارة'],
        'chat' => ['id' => -100909, 'type' => 'supergroup', 'title' => 'مجموعة الاختبار'],
        'text' => $text,
    ], $overrides));
}

it('keeps group chat and requester details in an ephemeral info response', function () {
    $api = new FakeTelegramApi;

    (new InfoHandler($api))->handle(requesterNoticeMessage('/info'));

    $ephemeral = json_decode($api->sentMessages[0]['ephemeral_message_parameters'], true, flags: JSON_THROW_ON_ERROR);

    expect($api->sentMessages[0]['text'])->toContain('معلومات الدردشة')
        ->and($ephemeral)->toBe(['receiver_user_id' => 404]);
});

it('keeps info responses persistent in private chats', function () {
    $api = new FakeTelegramApi;

    (new InfoHandler($api))->handle(requesterNoticeMessage('/info', [
        'chat' => ['id' => 404, 'type' => 'private', 'first_name' => 'سارة'],
    ]));

    expect($api->sentMessages[0])->not->toHaveKey('ephemeral_message_parameters');
});

it('shows private-forward validation feedback only to its requester in a group', function () {
    $api = new FakeTelegramApi;

    (new PrivateForwardHandler($api))->handle(requesterNoticeMessage('/pforward -100123'));

    $ephemeral = json_decode($api->sentMessages[0]['ephemeral_message_parameters'], true, flags: JSON_THROW_ON_ERROR);

    expect($api->sentMessages[0]['text'])->toContain('يجب أن ترد على رسالة')
        ->and($ephemeral)->toBe(['receiver_user_id' => 404]);
});
