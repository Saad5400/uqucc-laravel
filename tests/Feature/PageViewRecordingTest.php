<?php

use App\Models\Page;
use App\Models\PageViewStat;
use Database\Factories\PageFactory;
use Illuminate\Support\Facades\Cache;

/*
| Which hits count as a page view (TrackPageViews → PageViewRecorder). The
| write itself runs after the response is sent; the test client runs those
| deferred callbacks when it terminates the request.
*/

const READER_UA = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36';

function viewPage(string $uri, string $ip = '203.0.113.20', ?string $userAgent = READER_UA): Illuminate\Testing\TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(['User-Agent' => (string) $userAgent])
        ->get($uri);
}

beforeEach(function (): void {
    $this->page = PageFactory::new()->create(['slug' => '/altkhssat', 'title' => 'التخصصات']);
});

it('counts a reader\'s view of a content page', function (): void {
    viewPage('/altkhssat')->assertOk();

    expect(PageViewStat::query()->sole())
        ->page_id->toBe($this->page->id)
        ->ip_address->toBe('203.0.113.20')
        ->user_agent->toBe(READER_UA)
        ->view_count->toBe(1);
});

it('does not count loopback hits (container health checks)', function (string $ip): void {
    viewPage('/altkhssat', ip: $ip)->assertOk();

    expect(PageViewStat::query()->count())->toBe(0);
})->with(['127.0.0.1', '::1']);

it('does not count bots, crawlers, link previews and HTTP clients', function (?string $userAgent): void {
    viewPage('/altkhssat', userAgent: $userAgent)->assertOk();

    expect(PageViewStat::query()->count())->toBe(0);
})->with([
    'googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'bingbot' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
    'telegram preview' => 'TelegramBot (like TwitterBot)',
    'whatsapp preview' => 'WhatsApp/2.23.20.0',
    'facebook scraper' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    'headless chrome' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/131.0 Safari/537.36',
    'curl' => 'curl/8.5.0',
    'python' => 'python-requests/2.32.3',
    'uptime monitor' => 'Uptime-Kuma/1.23.0',
    'no user agent' => '',
]);

it('still counts a phone whose model name ends in "bot"', function (): void {
    viewPage('/altkhssat', userAgent: 'Mozilla/5.0 (Linux; Android 10; CUBOT X30) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36')->assertOk();

    expect(PageViewStat::query()->count())->toBe(1);
});

it('counts nothing for a path that is not a page, or a failed response', function (): void {
    viewPage('/no-such-page')->assertNotFound();
    viewPage('/robots.txt')->assertOk();

    expect(PageViewStat::query()->count())->toBe(0);
});

it('records the signed-in user on their view', function (): void {
    $user = App\Models\User::factory()->create();

    $this->actingAs($user);
    viewPage('/altkhssat')->assertOk();

    expect(PageViewStat::query()->sole()->user_id)->toBe($user->id);
});

it('resolves slugs from one cached map that page writes refresh', function (): void {
    expect(Page::idForSlug('/altkhssat'))->toBe($this->page->id)
        ->and(Cache::has(config('app-cache.keys.page_ids_by_slug')))->toBeTrue()
        ->and(Page::idForSlug('/missing'))->toBeNull();

    $this->page->update(['slug' => '/altakhassusat']);

    expect(Page::idForSlug('/altkhssat'))->toBeNull()
        ->and(Page::idForSlug('/altakhassusat'))->toBe($this->page->id);

    $this->page->delete();

    expect(Page::idForSlug('/altakhassusat'))->toBeNull();
});
