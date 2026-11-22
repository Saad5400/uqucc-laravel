<?php

use App\Http\Middleware\CacheResponse;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\PageViewStat;
use Database\Factories\PageFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/*
| A full-page cache hit for a visitor without a session is answered by
| ServeCachedPage, before the web group would start (and store) a session.
| The session driver is `database` here so a started session shows up as a row.
*/

const BROWSER_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

beforeEach(function (): void {
    Config::set('session.driver', 'database');
    PageFactory::new()->create(['slug' => '/altkhssat', 'title' => 'التخصصات']);
});

function visitAsGuest(string $uri, array $headers = []): Illuminate\Testing\TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->withHeaders(['User-Agent' => BROWSER_UA, ...$headers])
        ->get($uri);
}

it('answers a cached page without starting a session', function (): void {
    visitAsGuest('/altkhssat')->assertOk()->assertHeaderMissing('X-Cache');
    expect(DB::table('sessions')->count())->toBe(1);

    $hit = visitAsGuest('/altkhssat');

    $hit->assertOk()->assertHeader('X-Cache', 'HIT');
    expect($hit->headers->getCookies())->toBe([])
        ->and(DB::table('sessions')->count())->toBe(1);
});

it('still counts the page view of a cached hit', function (): void {
    visitAsGuest('/altkhssat');
    visitAsGuest('/altkhssat')->assertHeader('X-Cache', 'HIT');

    expect(PageViewStat::query()->sole())
        ->ip_address->toBe('203.0.113.5')
        ->user_id->toBeNull()
        ->view_count->toBe(2);
});

it('leaves a visitor who may be signed in to the normal path', function (string $cookie): void {
    visitAsGuest('/altkhssat');

    $hit = test()->withCookie($cookie, 'anything')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->get('/altkhssat');

    // Still a cache hit (CacheResponse, inside the web group), but the session
    // layer ran: it answers with its cookie.
    $hit->assertOk()->assertHeader('X-Cache', 'HIT');
    expect(collect($hit->headers->getCookies())->map->getName())->toContain(config('session.cookie'));
})->with([
    'session cookie' => fn (): string => config('session.cookie'),
    'remember-me cookie' => 'remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d',
]);

it('lets Inertia answer a visit from an older build with a reload', function (): void {
    $version = (string) app(HandleInertiaRequests::class)->version(Request::create('/'));

    visitAsGuest('/altkhssat', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk();
    visitAsGuest('/altkhssat', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertHeader('X-Cache', 'HIT');

    visitAsGuest('/altkhssat', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'an-older-build'])->assertStatus(409);
});

it('never caches a URL with a query string', function (string $query): void {
    visitAsGuest('/altkhssat');

    // Not served from the clean URL's entry (its page object carries that URL)…
    visitAsGuest('/altkhssat?'.$query)->assertOk()->assertHeaderMissing('X-Cache');
    visitAsGuest('/altkhssat?'.$query)->assertOk()->assertHeaderMissing('X-Cache');

    // …and never stored under its own key.
    expect(Cache::has(CacheResponse::keyFor(url('/altkhssat?'.$query))))->toBeFalse()
        ->and(Cache::has(CacheResponse::keyFor(url('/altkhssat'))))->toBeTrue();
})->with([
    'utm' => 'utm_source=telegram&utm_medium=social',
    'fbclid' => 'fbclid=IwAR0abc',
    'anything else' => 'v=1',
]);
