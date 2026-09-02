<?php

use App\Models\Page;
use App\Services\OgImageService;
use App\Services\QuickResponseService;
use App\Services\Telegram\Handlers\UquccSearchHandler;
use App\Services\TipTapContentExtractor;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

function searchHandler(FakeTelegramApi $api): UquccSearchHandler
{
    return new UquccSearchHandler(
        $api,
        app(QuickResponseService::class),
        app(TipTapContentExtractor::class),
        app(OgImageService::class),
    );
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

/** A page whose TipTap document has no blocks at all — a section, not an article. */
function sectionPage(array $attributes = []): Page
{
    return Page::factory()->create(array_merge([
        'title' => 'المقررات',
        'slug' => '/courses',
        'html_content' => ['type' => 'doc', 'content' => []],
        'quick_response_auto_extract_message' => true,
        'quick_response_auto_extract_buttons' => true,
        'quick_response_auto_extract_attachments' => true,
    ], $attributes));
}

/**
 * @return array<int, array<int, array{text: string, url: string}>>
 */
function keyboardOf(array $params): array
{
    return json_decode($params['reply_markup'], true)['inline_keyboard'];
}

it('answers a section page with its sub-pages as website buttons instead of a card', function () {
    $section = sectionPage();
    $first = Page::factory()->childOf($section)->create(['title' => 'هياكل البيانات', 'slug' => '/courses/data-structures', 'order' => 1]);
    $second = Page::factory()->childOf($section)->create(['title' => 'الخوارزميات', 'slug' => '/courses/algorithms', 'order' => 2]);

    $api = new FakeTelegramApi;
    searchHandler($api)->handle(pageLookupMessage('المقررات'));

    expect($api->sentPhotos)->toBeEmpty()
        ->and($api->sentMessages)->toHaveCount(1);

    $sent = $api->sentMessages[0];

    expect($sent['text'])->toContain('<b>المقررات</b>')
        ->and($sent['text'])->toContain('اختر واحدة من الأزرار')
        ->and($sent['parse_mode'])->toBe('HTML')
        ->and(keyboardOf($sent))->toBe([
            [['text' => 'هياكل البيانات', 'url' => url($first->slug)]],
            [['text' => 'الخوارزميات', 'url' => url($second->slug)]],
        ]);
});

it('never links a sub-page that is hidden from the website or from the bot', function () {
    $section = sectionPage();
    Page::factory()->childOf($section)->hidden()->create(['title' => 'مسودة', 'order' => 1]);
    Page::factory()->childOf($section)->hiddenFromBot()->create(['title' => 'للموقع فقط', 'order' => 2]);
    $shown = Page::factory()->childOf($section)->create(['title' => 'الظاهرة', 'order' => 3]);

    $api = new FakeTelegramApi;
    searchHandler($api)->handle(pageLookupMessage('المقررات'));

    expect(keyboardOf($api->sentMessages[0]))->toBe([
        [['text' => 'الظاهرة', 'url' => url($shown->slug)]],
    ]);
});

it('appends the sub-page buttons below a content page\'s own buttons', function () {
    $section = Page::factory()->create([
        'title' => 'التقنية',
        'slug' => '/technology',
        'quick_response_message' => 'كل ما يخص الأجهزة والبرامج.',
        'quick_response_buttons' => [
            ['text' => 'متجر الجامعة', 'url' => 'https://store.example.com', 'size' => 'full'],
        ],
    ]);
    $child = Page::factory()->childOf($section)->create(['title' => 'اللابتوب المناسب', 'slug' => '/technology/laptop']);

    $api = new FakeTelegramApi;
    searchHandler($api)->handle(pageLookupMessage('التقنية'));

    $sent = $api->sentMessages[0];

    expect($sent['text'])->toContain('كل ما يخص الأجهزة والبرامج.')
        ->and($sent['text'])->not->toContain('اختر واحدة من الأزرار')
        ->and(keyboardOf($sent))->toBe([
            [['text' => 'متجر الجامعة', 'url' => 'https://store.example.com']],
            [['text' => 'اللابتوب المناسب', 'url' => url($child->slug)]],
        ]);
});

it('folds a long list of sub-pages into a show-all button that opens the section', function () {
    $section = sectionPage();

    foreach (range(1, 12) as $index) {
        Page::factory()->childOf($section)->create(['title' => "صفحة {$index}", 'order' => $index]);
    }

    $api = new FakeTelegramApi;
    searchHandler($api)->handle(pageLookupMessage('المقررات'));

    $keyboard = keyboardOf($api->sentMessages[0]);

    expect($keyboard)->toHaveCount(11)
        ->and($keyboard[0][0]['text'])->toBe('صفحة 1')
        ->and($keyboard[9][0]['text'])->toBe('صفحة 10')
        ->and($keyboard[10][0])->toBe(['text' => 'عرض كل الصفحات (12)', 'url' => url($section->slug)]);
});

it('leaves a leaf page without content on its old path', function () {
    $leaf = sectionPage(['title' => 'صفحة فارغة', 'slug' => '/empty']);

    $api = new FakeTelegramApi;

    $handler = new class($api) extends UquccSearchHandler
    {
        public array $screenshotCalls = [];

        public function __construct(FakeTelegramApi $api)
        {
            parent::__construct($api, app(QuickResponseService::class), app(TipTapContentExtractor::class), app(OgImageService::class));
        }

        protected function sendScreenshotWithText(Message $message, Page $page, string $caption, ?string $replyMarkup = null): void
        {
            $this->screenshotCalls[] = ['page' => $page->id, 'markup' => $replyMarkup];
        }
    };

    $handler->handle(pageLookupMessage('صفحة فارغة'));

    expect($api->sentMessages)->toBeEmpty()
        ->and($handler->screenshotCalls)->toBe([['page' => $leaf->id, 'markup' => null]]);
});
