<?php

use App\Models\BotCommandStat;
use App\Models\TelegramChatSetting;
use App\Services\Telegram\Handlers\AiToggleHandler;
use App\Settings\AiSettings;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

beforeEach(function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->telegram_ai_enabled = true;
    $settings->save();
});

function toggleMessage(array $overrides = []): Message
{
    return new Message(array_replace_recursive([
        'message_id' => 10,
        'from' => ['id' => 501, 'is_bot' => false, 'first_name' => 'سعد'],
        'chat' => ['id' => 900123, 'type' => 'private', 'first_name' => 'سعد'],
        'text' => '/ai_on',
    ], $overrides));
}

function groupToggleMessage(string $text, int $userId = 501): Message
{
    return toggleMessage([
        'from' => ['id' => $userId],
        'chat' => ['id' => -100777, 'type' => 'supergroup', 'title' => 'مجموعة الكلية'],
        'text' => $text,
    ]);
}

it('enables the assistant for a private chat with an arabic confirmation', function () {
    $api = new FakeTelegramApi;

    (new AiToggleHandler($api))->handle(toggleMessage());

    $setting = TelegramChatSetting::query()->sole();

    expect($setting->chat_id)->toBe(900123)
        ->and($setting->ai_enabled)->toBeTrue()
        ->and($setting->type)->toBe('private')
        ->and($setting->title)->toBe('سعد')
        ->and($setting->enabled_by)->toBe('501')
        ->and($api->sentMessages[0]['text'])->toContain('تم تفعيل المساعد الذكي');

    expect(BotCommandStat::query()->where('command_name', '/ai_on')->exists())->toBeTrue();
});

it('lets a telegram-side group admin enable the assistant', function () {
    $api = new FakeTelegramApi;
    $api->chatMemberStatuses[501] = 'administrator';

    (new AiToggleHandler($api))->handle(groupToggleMessage('/ai_on'));

    $setting = TelegramChatSetting::query()->sole();

    expect($setting->chat_id)->toBe(-100777)
        ->and($setting->ai_enabled)->toBeTrue()
        ->and($setting->title)->toBe('مجموعة الكلية')
        ->and($api->sentMessages[0]['text'])->toContain('تم تفعيل المساعد الذكي');
});

it('refuses the toggle for non-admin group members', function (string $command) {
    $api = new FakeTelegramApi;
    $api->chatMemberStatuses[501] = 'member';

    (new AiToggleHandler($api))->handle(groupToggleMessage($command));

    expect(TelegramChatSetting::query()->count())->toBe(0)
        ->and($api->sentMessages[0]['text'])->toContain('لمشرفي المجموعة فقط');
})->with(['/ai_on', '/ai_off', '/ai_new']);

it('matches the command with the bot username suffix', function () {
    $api = new FakeTelegramApi;
    $api->chatMemberStatuses[501] = 'creator';

    (new AiToggleHandler($api))->handle(groupToggleMessage('/ai_on@UquccTestBot'));

    expect(TelegramChatSetting::query()->sole()->ai_enabled)->toBeTrue();
});

it('disables the assistant with /ai_off', function () {
    TelegramChatSetting::factory()->aiEnabled()->create(['chat_id' => 900123]);

    $api = new FakeTelegramApi;

    (new AiToggleHandler($api))->handle(toggleMessage(['text' => '/ai_off']));

    expect(TelegramChatSetting::query()->sole()->ai_enabled)->toBeFalse()
        ->and($api->sentMessages[0]['text'])->toContain('تم إيقاف المساعد الذكي');
});

it('resets the stored conversation with /ai_new', function () {
    TelegramChatSetting::factory()->aiEnabled()->create([
        'chat_id' => 900123,
        'conversation_id' => '0197fa00-0000-7000-8000-000000000000',
    ]);

    $api = new FakeTelegramApi;

    (new AiToggleHandler($api))->handle(toggleMessage(['text' => '/ai_new']));

    expect(TelegramChatSetting::query()->sole()->conversation_id)->toBeNull()
        ->and($api->sentMessages[0]['text'])->toContain('محادثة جديدة');
});

it('points /ai_new at /ai_on when the assistant is not activated', function () {
    $api = new FakeTelegramApi;

    (new AiToggleHandler($api))->handle(toggleMessage(['text' => '/ai_new']));

    expect(TelegramChatSetting::query()->count())->toBe(0)
        ->and($api->sentMessages[0]['text'])->toContain('/ai_on');
});

it('notes the global kill switch inside the /ai_on confirmation when the feature is off', function () {
    $settings = app(AiSettings::class);
    $settings->telegram_ai_enabled = false;
    $settings->save();

    $api = new FakeTelegramApi;

    (new AiToggleHandler($api))->handle(toggleMessage());

    expect(TelegramChatSetting::query()->sole()->ai_enabled)->toBeTrue()
        ->and($api->sentMessages[0]['text'])->toContain('موقوفة حالياً من إدارة الموقع');
});

it('ignores unrelated messages', function (string $text) {
    $api = new FakeTelegramApi;

    (new AiToggleHandler($api))->handle(toggleMessage(['text' => $text]));

    expect($api->sentMessages)->toBe([])
        ->and(TelegramChatSetting::query()->count())->toBe(0);
})->with(['مرحبا', '/help', '/ai_onx', 'ai_on']);
