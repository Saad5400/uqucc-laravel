<?php

use App\Models\BotCommandStat;
use App\Services\Numbers\BaseConversion;
use App\Services\Numbers\BaseConversionImageRenderer;
use App\Services\Numbers\BaseConverter;
use App\Services\Telegram\Handlers\BaseConversionHandler;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

/**
 * A renderer that answers with a real (tiny) PNG without going near Takumi —
 * the card itself is rendered for real in
 * tests/Feature/Tools/BaseConversionCardRenderingTest.php, and repeating that
 * here would put a Node process in every handler assertion.
 */
function stubBaseConversionRenderer(?Exception $failure = null): BaseConversionImageRenderer
{
    return new class($failure) extends BaseConversionImageRenderer
    {
        public function __construct(private readonly ?Exception $failure) {}

        public function render(BaseConversion $conversion): string
        {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            ob_start();
            imagepng(imagecreatetruecolor(8, 8));

            return (string) ob_get_clean();
        }
    };
}

function baseConversionMessage(string $text): Message
{
    return new Message([
        'message_id' => 11,
        'from' => ['id' => 502, 'is_bot' => false, 'first_name' => 'سعد'],
        'chat' => ['id' => 900124, 'type' => 'private', 'first_name' => 'سعد'],
        'text' => $text,
    ]);
}

function handleBaseConversion(string $text, ?Exception $renderFailure = null): FakeTelegramApi
{
    $api = new FakeTelegramApi;

    (new BaseConversionHandler($api, app(BaseConverter::class), stubBaseConversionRenderer($renderFailure)))
        ->handle(baseConversionMessage($text));

    return $api;
}

it('replies with the conversion card for «حول ... من ... إلى ...»', function () {
    $api = handleBaseConversion('حول 2AF من 16 إلى 2');

    expect($api->sentMessages)->toBe([])
        ->and($api->sentPhotos)->toHaveCount(1);

    $sent = $api->sentPhotos[0];

    expect($sent['photo'])->toBeInstanceOf(InputFile::class)
        ->and($sent['caption'])->toBe('2AF₁₆ = 1010101111₂')
        ->and($sent['parse_mode'])->toBe('HTML');

    expect(BotCommandStat::query()->where('command_name', 'base_conversion')->exists())->toBeTrue();
});

it('answers the alternative triggers and phrasings', function (string $command) {
    $api = handleBaseConversion($command);

    expect($api->sentPhotos[0]['caption'])->toBe('255₁₀ = 11111111₂');
})->with([
    'حوّل 255 من 10 إلى 2',
    'تحويل 255 من 10 الى 2',
    'حول 255 من عشري إلى ثنائي',
    'حول ٢٥٥ من الأساس ١٠ إلى الأساس ٢',
    '/base 255 10 2',
    '/convert 255 decimal binary',
]);

it('replies with the error for a digit outside the base', function () {
    $api = handleBaseConversion('حول 12A من 10 إلى 2');

    expect($api->sentPhotos)->toBe([])
        ->and($api->sentMessages[0]['text'])->toContain('ليس رقمًا في الأساس 10');
});

it('points to the web tool when the working is too long to draw', function () {
    $api = handleBaseConversion('حول 99999999999999 من 10 إلى 2');

    expect($api->sentPhotos)->toBe([])
        ->and($api->sentMessages[0]['text'])->toContain('أطول من أن تُعرض هنا')
        ->and($api->sentMessages[0]['text'])->toContain('/adwat/tahwel-alaadad');
});

it('falls back to the text working when the card cannot be drawn', function () {
    $api = handleBaseConversion('حول 2AF من 16 إلى 2', new RuntimeException('takumi is down'));

    expect($api->sentPhotos)->toBe([])
        ->and($api->sentMessages[0]['text'])->toContain('2AF₁₆ = 1010101111₂')
        ->and($api->sentMessages[0]['text'])->toContain('القسمة المتكررة على 2')
        ->and($api->sentMessages[0]['parse_mode'])->toBe('HTML');
});

it('answers a slash command whose arguments do not parse with usage', function (string $text) {
    $api = handleBaseConversion($text);

    expect($api->sentPhotos)->toBe([])
        ->and($api->sentMessages[0]['text'])->toContain('حول [العدد] من [أساس] إلى [أساس]');
})->with(['/base', '/convert 255', '/base 255 من 10 إلى سباعي']);

it('stays silent on messages that only look like the command', function (string $text) {
    $api = handleBaseConversion($text);

    expect($api->sentMessages)->toBe([])
        ->and($api->sentPhotos)->toBe([]);
})->with([
    'ordinary Arabic' => ['حول الجامعة من الشمال إلى الجنوب'],
    'a question about the tool' => ['تحويل الأعداد كيف يشتغل'],
    'the trigger alone' => ['حول'],
    'an unknown base name' => ['حول 255 من عشري إلى سباعي'],
    'unrelated' => ['مرحبا'],
    'another command' => ['/help'],
]);
