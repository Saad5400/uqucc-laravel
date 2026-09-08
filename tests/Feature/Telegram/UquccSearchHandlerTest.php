<?php

use App\Models\Page;
use App\Services\QuickResponseService;
use App\Services\Telegram\Handlers\UquccSearchHandler;
use App\Services\Telegram\PageReplyComposer;
use App\Support\Disk;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

function searchHandler(FakeTelegramApi $api): UquccSearchHandler
{
    return new UquccSearchHandler($api, app(QuickResponseService::class), app(PageReplyComposer::class));
}

function pageLookupMessage(string $text): Message
{
    return new Message([
        'message_id' => 20,
        'from' => ['id' => 501, 'is_bot' => false, 'first_name' => 'سعد'],
        'chat' => ['id' => -100200, 'type' => 'supergroup', 'title' => 'قروب الدفعة'],
        'text' => $text,
    ]);
}

function pageWithContent(array $blocks, array $attributes = []): Page
{
    return Page::factory()->create(array_merge([
        'title' => 'المقررات',
        'slug' => '/courses',
        'html_content' => ['type' => 'doc', 'content' => $blocks],
        'quick_response_auto_extract_message' => true,
        'quick_response_auto_extract_buttons' => true,
        'quick_response_auto_extract_attachments' => true,
    ], $attributes))->fresh();
}

function textParagraph(string $text): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
}

/** A real PNG on the (faked) media disk, so the handler can sniff it as an image. */
function storedImage(string $name): array
{
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);
    Storage::disk(Disk::MEDIA)->put("uploads/{$name}", (string) ob_get_clean());
    imagedestroy($image);

    return ['type' => 'image', 'attrs' => ['src' => "/storage/uploads/{$name}"]];
}

beforeEach(function () {
    Storage::fake(Disk::MEDIA);
});

it('answers a page title with one text message: the content, buttons under it, no preview', function () {
    $page = pageWithContent([textParagraph('كل ما يخص المقررات.')]);
    $child = Page::factory()->childOf($page)->create(['title' => 'الفيزياء', 'slug' => '/courses/physics']);

    $api = new FakeTelegramApi;
    searchHandler($api)->handle(pageLookupMessage('المقررات'));

    expect($api->sentPhotos)->toBeEmpty()
        ->and($api->sentMediaGroups)->toBeEmpty()
        ->and($api->sentMessages)->toHaveCount(1);

    $sent = $api->sentMessages[0];

    expect($sent['chat_id'])->toBe(-100200)
        ->and($sent['reply_to_message_id'])->toBe(20)
        ->and($sent['parse_mode'])->toBe('HTML')
        ->and($sent['text'])->toContain('كل ما يخص المقررات.')
        ->and($sent['text'])->not->toContain('<blockquote')
        ->and(json_decode($sent['link_preview_options'], true))->toBe(['is_disabled' => true])
        ->and(json_decode($sent['reply_markup'], true)['inline_keyboard'])->toBe([
            [['text' => 'الفيزياء', 'url' => url($child->slug)]],
        ]);
});

it('sends a section page as its title over the sub-page buttons, with no image', function () {
    $section = pageWithContent([]);
    Page::factory()->childOf($section)->create(['title' => 'الأولى', 'order' => 1]);
    Page::factory()->childOf($section)->create(['title' => 'الثانية', 'order' => 2]);

    $api = new FakeTelegramApi;
    searchHandler($api)->handle(pageLookupMessage('المقررات'));

    expect($api->sentPhotos)->toBeEmpty()
        ->and($api->sentMessages)->toHaveCount(1)
        ->and($api->sentMessages[0]['text'])->toBe('<a href="'.url('/courses').'"><b>المقررات</b></a>')
        ->and(collect(json_decode($api->sentMessages[0]['reply_markup'], true)['inline_keyboard'])->flatten(1)->pluck('text')->all())->toBe(['الأولى', 'الثانية']);
});

it('sends a page\'s album with the reply as its caption, in one message', function () {
    pageWithContent([textParagraph('خطوات التفعيل'), storedImage('one.png'), storedImage('two.png')]);

    $api = new FakeTelegramApi;
    searchHandler($api)->handle(pageLookupMessage('المقررات'));

    expect($api->sentMediaGroups)->toHaveCount(1)
        ->and($api->sentMessages)->toBeEmpty();

    $album = $api->sentMediaGroups[0];
    $media = json_decode($album['media'], true);

    expect($album['reply_to_message_id'])->toBe(20)
        ->and($media)->toHaveCount(2)
        ->and(collect($media)->pluck('type')->unique()->all())->toBe(['photo'])
        ->and($media[0]['caption'])->toContain('خطوات التفعيل')
        ->and($media[0]['parse_mode'])->toBe('HTML')
        ->and($media[1])->not->toHaveKey('caption');
});

it('sends a single image with the reply as its caption and the buttons under it', function () {
    $page = pageWithContent([textParagraph('خريطة الحرم'), storedImage('map.png')]);
    $child = Page::factory()->childOf($page)->create(['title' => 'المداخل', 'slug' => '/courses/gates']);

    $api = new FakeTelegramApi;
    searchHandler($api)->handle(pageLookupMessage('المقررات'));

    expect($api->sentPhotos)->toHaveCount(1)
        ->and($api->sentMediaGroups)->toBeEmpty()
        ->and($api->sentMessages)->toBeEmpty();

    $photo = $api->sentPhotos[0];

    expect($photo['chat_id'])->toBe(-100200)
        ->and($photo['reply_to_message_id'])->toBe(20)
        ->and($photo['parse_mode'])->toBe('HTML')
        ->and($photo['caption'])->toContain('خريطة الحرم')
        ->and(json_decode($photo['reply_markup'], true)['inline_keyboard'])->toBe([
            [['text' => 'المداخل', 'url' => url($child->slug)]],
        ]);
});

it('keeps an album\'s buttons in a message of their own, since Telegram hangs none under one', function () {
    $page = pageWithContent([textParagraph('خطوات التفعيل'), storedImage('one.png'), storedImage('two.png')]);
    $child = Page::factory()->childOf($page)->create(['title' => 'الفيزياء', 'slug' => '/courses/physics']);

    $api = new FakeTelegramApi;
    searchHandler($api)->handle(pageLookupMessage('المقررات'));

    expect($api->sentMediaGroups)->toHaveCount(1)
        ->and($api->sentMessages)->toHaveCount(1)
        ->and(json_decode($api->sentMediaGroups[0]['media'], true)[0])->not->toHaveKey('caption')
        ->and($api->sentMessages[0]['text'])->toContain('خطوات التفعيل')
        ->and(json_decode($api->sentMessages[0]['reply_markup'], true)['inline_keyboard'])->toBe([
            [['text' => 'الفيزياء', 'url' => url($child->slug)]],
        ]);
});

it('sends a reply too long for a caption as its own message under the image', function () {
    $paragraph = str_repeat('تفاصيل التسجيل والقبول والمواعيد. ', 12);
    pageWithContent([
        ...array_map(fn (int $i): array => textParagraph("{$i} {$paragraph}"), range(1, 4)),
        storedImage('map.png'),
    ]);

    $api = new FakeTelegramApi;
    searchHandler($api)->handle(pageLookupMessage('المقررات'));

    expect($api->sentPhotos)->toHaveCount(1)
        ->and($api->sentPhotos[0])->not->toHaveKey('caption')
        ->and($api->sentMessages)->toHaveCount(1)
        ->and($api->sentMessages[0]['text'])->toContain('تفاصيل التسجيل');
});

it('resends a refused caption unquoted rather than not at all', function () {
    pageWithContent([
        ...array_map(fn (int $i): array => textParagraph("سطر {$i}"), range(1, 20)),
        storedImage('map.png'),
    ]);

    $api = new FakeTelegramApi;
    $api->sendPhotoFailures = ["Bad Request: can't parse entities"];

    searchHandler($api)->handle(pageLookupMessage('المقررات'));

    expect($api->sentPhotos)->toHaveCount(1)
        ->and($api->sentPhotos[0]['caption'])->not->toContain('<blockquote')
        ->and($api->sentPhotos[0]['caption'])->toContain('سطر 1')
        ->and($api->sentMessages)->toBeEmpty();
});

it('lets Telegram draw the page preview for an image-heavy page and sends no album', function () {
    $images = array_map(fn (int $i): array => storedImage("shot-{$i}.png"), range(1, 6));
    pageWithContent([textParagraph('تثبيت المحرر'), ...$images]);

    $api = new FakeTelegramApi;
    searchHandler($api)->handle(pageLookupMessage('المقررات'));

    expect($api->sentMediaGroups)->toBeEmpty()
        ->and($api->sentPhotos)->toBeEmpty()
        ->and($api->sentMessages)->toHaveCount(1)
        ->and(json_decode($api->sentMessages[0]['link_preview_options'], true)['url'])->toBe(url('/courses'));
});

it('resends the reply unquoted when Telegram refuses the markup', function () {
    pageWithContent(array_map(fn (int $i): array => textParagraph("نص الصفحة {$i}"), range(1, 20)));

    $api = new FakeTelegramApi;
    $api->sendMessageFailures = ["Bad Request: can't parse entities"];

    searchHandler($api)->handle(pageLookupMessage('المقررات'));

    expect($api->sentMessages)->toHaveCount(1)
        ->and($api->sentMessages[0]['text'])->not->toContain('<blockquote')
        ->and($api->sentMessages[0]['text'])->toContain('نص الصفحة 1');
});

it('lets a failure through when there is no unquoted version to fall back to', function () {
    pageWithContent([]);

    $api = new FakeTelegramApi;
    $api->sendMessageFailures = ['Bad Request: chat not found'];

    expect(fn () => searchHandler($api)->handle(pageLookupMessage('المقررات')))
        ->toThrow(RuntimeException::class, 'chat not found');
});
