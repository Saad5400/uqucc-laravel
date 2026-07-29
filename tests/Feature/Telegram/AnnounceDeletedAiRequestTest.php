<?php

use App\Jobs\AnnounceDeletedAiRequest;
use App\Services\Telegram\MessagePresenceProbe;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeTelegramApi;

function deletionWatch(FakeTelegramApi $api, array $overrides = []): AnnounceDeletedAiRequest
{
    $arguments = array_replace([
        'chatId' => -100777,
        'requestMessageId' => 20,
        'replyMessageId' => 21,
        'requestText' => 'سيك كم مكافأة الامتياز؟',
        'senderId' => 501,
        'senderName' => 'سعد',
        'senderUsername' => 'saad',
        'attachmentLabel' => null,
        'probe' => 0,
    ], $overrides);

    return new AnnounceDeletedAiRequest(...$arguments);
}

function runWatch(FakeTelegramApi $api, array $overrides = []): void
{
    deletionWatch($api, $overrides)->handle($api, new MessagePresenceProbe($api));
}

it('posts the deleted question under the answer and mentions the asker', function () {
    Queue::fake();

    $api = new FakeTelegramApi;
    $api->missingMessageIds = [20];

    runWatch($api);

    $announcement = $api->sentMessages[0];

    expect($announcement['chat_id'])->toBe(-100777)
        ->and($announcement['reply_to_message_id'])->toBe(21)
        ->and($announcement['parse_mode'])->toBe('HTML')
        ->and($announcement['text'])->toContain('<a href="tg://user?id=501">سعد</a>')
        ->and($announcement['text'])->toContain('(@saad)')
        ->and($announcement['text'])->toContain('سيك كم مكافأة الامتياز؟');

    Queue::assertNothingPushed();
});

it('notes the attachment a deleted ask carried', function () {
    Queue::fake();

    $api = new FakeTelegramApi;
    $api->missingMessageIds = [20];

    runWatch($api, ['attachmentLabel' => 'ملف: خطة.pdf']);

    expect($api->sentMessages[0]['text'])->toContain('ملف: خطة.pdf');
});

it('escapes html in the question and the asker name', function () {
    Queue::fake();

    $api = new FakeTelegramApi;
    $api->missingMessageIds = [20];

    runWatch($api, [
        'requestText' => 'سيك <b>ما</b> هذا؟',
        'senderName' => '<script>',
        'senderUsername' => null,
    ]);

    expect($api->sentMessages[0]['text'])->toContain('&lt;b&gt;ما&lt;/b&gt;')
        ->and($api->sentMessages[0]['text'])->toContain('&lt;script&gt;')
        ->and($api->sentMessages[0]['text'])->not->toContain('<script>');
});

it('says nothing and probes again while the question is still there', function () {
    Queue::fake();

    $api = new FakeTelegramApi;

    runWatch($api);

    expect($api->sentMessages)->toBe([])
        ->and($api->reactions[0]['message_id'])->toBe(20);

    Queue::assertPushed(AnnounceDeletedAiRequest::class, fn (AnnounceDeletedAiRequest $job): bool => $job->probe === 1);
});

it('keeps probing when the api answer is inconclusive', function () {
    Queue::fake();

    $api = new FakeTelegramApi;
    $api->reactionError = 'Bad Request: REACTIONS_TOO_MANY';

    runWatch($api);

    expect($api->sentMessages)->toBe([])
        ->and($api->editedReplyMarkups)->toHaveCount(1);

    Queue::assertPushed(AnnounceDeletedAiRequest::class);
});

it('falls back to the reply-markup probe when reactions are unavailable', function () {
    Queue::fake();

    $api = new FakeTelegramApi;
    $api->reactionError = 'Bad Request: not enough rights to send reactions';
    $api->missingMessageIds = [20];

    runWatch($api);

    expect($api->sentMessages[0]['text'])->toContain('سيك كم مكافأة الامتياز؟');
});

it('stops watching after the last probe', function () {
    Queue::fake();

    $api = new FakeTelegramApi;

    runWatch($api, ['probe' => count(AnnounceDeletedAiRequest::PROBE_DELAYS) - 1]);

    Queue::assertNothingPushed();
});
