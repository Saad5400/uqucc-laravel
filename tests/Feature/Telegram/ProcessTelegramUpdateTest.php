<?php

use App\Jobs\ProcessTelegramUpdate;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
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

/**
 * Redirect the temporary telegram_old_reply channel into an in-memory Monolog
 * handler so tests can assert what the diagnostic logged.
 */
function captureOldReplyLog(): TestHandler
{
    $handler = new TestHandler;
    Log::channel('telegram_old_reply')->getLogger()->setHandlers([$handler]);

    return $handler;
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

it('does not flag a recent, non-reply message', function () {
    $log = captureOldReplyLog();

    runUpdate([
        'update_id' => 10,
        'message' => helpMessagePayload(['date' => now()->getTimestamp()]),
    ]);

    expect($log->getRecords())->toBeEmpty();
});

it('flags a new message that replies to a message older than 30 minutes', function () {
    $log = captureOldReplyLog();

    runUpdate([
        'update_id' => 11,
        'message' => helpMessagePayload([
            'message_id' => 900,
            'date' => now()->getTimestamp(),
            'text' => 'خريطة الزاهر',
            'reply_to_message' => [
                'message_id' => 12,
                'date' => now()->subMinutes(45)->getTimestamp(),
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'طالب'],
                'chat' => ['id' => 900123, 'type' => 'supergroup', 'title' => 'مجموعة الكلية'],
                'text' => 'وين قاعة الامتحان؟',
            ],
        ]),
    ]);

    expect($log->hasWarningRecords())->toBeTrue();

    $context = $log->getRecords()[0]['context'];

    expect($context['update_type'])->toBe('message')
        ->and($context['is_reply_to_older_message'])->toBeTrue()
        ->and($context['reply_target_message_id'])->toBe(12)
        ->and($context['reply_target_age_seconds'])->toBeGreaterThanOrEqual(30 * 60)
        ->and($context['reply_to']['message_id'])->toBe(12)
        ->and($context['incoming']['text'])->toBe('خريطة الزاهر')
        ->and($context['raw_update']['update_id'])->toBe(11);
});

it('flags an edited old message even though it is not answered', function () {
    $log = captureOldReplyLog();

    $fake = runUpdate([
        'update_id' => 12,
        'edited_message' => helpMessagePayload([
            'message_id' => 300,
            'date' => now()->subDays(32)->getTimestamp(),
            'edit_date' => now()->getTimestamp(),
            'text' => 'خريطة الزاهر',
        ]),
    ]);

    // Still not answered (edit guard), but captured for diagnosis.
    expect($fake->sentMessages)->toBeEmpty()
        ->and($fake->sentPhotos)->toBeEmpty()
        ->and($log->hasWarningRecords())->toBeTrue();

    $context = $log->getRecords()[0]['context'];

    expect($context['update_type'])->toBe('edited_message')
        ->and($context['is_reply_to_older_message'])->toBeFalse()
        ->and($context['reply_target_message_id'])->toBe(300)
        ->and($context['incoming']['edited_at'])->not->toBeNull();
});
