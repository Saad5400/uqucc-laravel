<?php

use App\Models\BotCommandStat;
use App\Services\Logic\TruthTableGenerator;
use App\Services\Logic\TruthTableImageRenderer;
use App\Services\Telegram\Handlers\TruthTableHandler;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

function truthTableMessage(string $text): Message
{
    return new Message([
        'message_id' => 10,
        'from' => ['id' => 501, 'is_bot' => false, 'first_name' => 'سعد'],
        'chat' => ['id' => 900123, 'type' => 'private', 'first_name' => 'سعد'],
        'text' => $text,
    ]);
}

function handleTruthTable(string $text): FakeTelegramApi
{
    $api = new FakeTelegramApi;

    (new TruthTableHandler($api, app(TruthTableGenerator::class), app(TruthTableImageRenderer::class)))
        ->handle(truthTableMessage($text));

    return $api;
}

it('replies with the truth table as a photo for «جدول الصواب»', function () {
    $api = handleTruthTable('جدول الصواب p and q');

    expect($api->sentMessages)->toBe([])
        ->and($api->sentPhotos)->toHaveCount(1);

    $sent = $api->sentPhotos[0];

    expect($sent['photo'])->toBeInstanceOf(InputFile::class)
        ->and($sent['caption'])->toContain('p ∧ q')
        ->and($sent['caption'])->toContain('ممكنة')
        ->and($sent['parse_mode'])->toBe('HTML');

    expect(BotCommandStat::query()->where('command_name', 'truth_table')->exists())->toBeTrue();
});

it('sends a valid png image', function () {
    $api = handleTruthTable('جدول الصواب p -> q');

    $contents = $api->sentPhotos[0]['photo']->getContents();
    $info = getimagesizefromstring($contents);

    expect($info['mime'])->toBe('image/png')
        ->and($info[0])->toBeGreaterThan(100)
        ->and($info[1])->toBeGreaterThan(100);
});

it('answers the alternative triggers', function (string $command) {
    $api = handleTruthTable($command.' p or not p');

    expect($api->sentPhotos[0]['caption'])->toContain('تحصيل حاصل');
})->with(['جدول الصدق', 'جدول الحقيقة', '/truthtable', '/truth']);

it('replies with the parse error for a malformed formula', function () {
    $api = handleTruthTable('جدول الصواب p and (q');

    expect($api->sentPhotos)->toBe([])
        ->and($api->sentMessages[0]['text'])->toContain('قوس غير مغلق');
});

it('points to the web tool when the table exceeds the image row limit', function () {
    $api = handleTruthTable('جدول الصواب a and b and c and d and e and f2 and g');

    expect($api->sentPhotos)->toBe([])
        ->and($api->sentMessages[0]['text'])->toContain('أكبر من أن يُعرض هنا')
        ->and($api->sentMessages[0]['text'])->toContain('/adwat/jdwal-alsawab');
});

it('ignores unrelated messages', function (string $text) {
    $api = handleTruthTable($text);

    expect($api->sentMessages)->toBe([])
        ->and($api->sentPhotos)->toBe([]);
})->with(['مرحبا', 'جدول الصواب', '/help', 'الصواب p and q']);
