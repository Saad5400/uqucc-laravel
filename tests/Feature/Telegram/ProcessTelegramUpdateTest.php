<?php

use App\Jobs\ProcessTelegramUpdate;
use Telegram\Bot\Api;
use Tests\Fakes\FakeTelegramApi;

/**
 * A ProcessTelegramUpdate whose handlers talk to an injected fake Api, so tests
 * can observe what the bot would send without hitting the Telegram Bot API.
 */
class RecordingProcessTelegramUpdate extends ProcessTelegramUpdate
{
    public function __construct(array $updateData, public FakeTelegramApi $fake)
    {
        parent::__construct($updateData);
    }

    protected function makeTelegram(): Api
    {
        return $this->fake;
    }
}

function runUpdate(array $updateData): FakeTelegramApi
{
    $fake = new FakeTelegramApi;
    (new RecordingProcessTelegramUpdate($updateData, $fake))->handle();

    return $fake;
}

function helpMessagePayload(array $overrides = []): array
{
    return array_replace([
        'message_id' => 55,
        'date' => now()->getTimestamp(),
        'from' => ['id' => 501, 'is_bot' => false, 'first_name' => 'سعد'],
        'chat' => ['id' => 900123, 'type' => 'private', 'first_name' => 'سعد'],
        'text' => '/help',
    ], $overrides);
}

it('answers a fresh message', function () {
    $fake = runUpdate([
        'update_id' => 1,
        'message' => helpMessagePayload(),
    ]);

    expect($fake->sentMessages)->not->toBeEmpty();
});

it('ignores an edited message so it never replies to old messages', function () {
    $fake = runUpdate([
        'update_id' => 2,
        'edited_message' => helpMessagePayload(),
    ]);

    expect($fake->sentMessages)->toBeEmpty()
        ->and($fake->sentPhotos)->toBeEmpty();
});

it('ignores an edited channel post', function () {
    $fake = runUpdate([
        'update_id' => 3,
        'edited_channel_post' => helpMessagePayload([
            'chat' => ['id' => -100777, 'type' => 'channel', 'title' => 'قناة الكلية'],
        ]),
    ]);

    expect($fake->sentMessages)->toBeEmpty()
        ->and($fake->sentPhotos)->toBeEmpty();
});
