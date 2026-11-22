<?php

use App\Support\BoundedSsrGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Ssr\Gateway;

/*
| Inertia's SSR call gives up after inertia.ssr.timeout seconds instead of the
| HTTP client's 30s and falls back to client rendering. A stuck SSR server
| must never hold a PHP thread (an Octane worker in particular) for long.
*/

beforeEach(function () {
    config([
        'inertia.ssr.enabled' => true,
        'inertia.ssr.ensure_bundle_exists' => false,
        'inertia.ssr.timeout' => 0.5,
    ]);
});

it('is the gateway Inertia renders through', function () {
    expect(app(Gateway::class))->toBeInstanceOf(BoundedSsrGateway::class);
});

it('falls back to client rendering when the SSR server never answers', function () {
    // Accepts connections (the kernel backlog does) but never responds,
    // like a frozen Node process.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int) substr((string) stream_socket_get_name($server, false), strrpos((string) stream_socket_get_name($server, false), ':') + 1);
    config(['inertia.ssr.url' => "http://127.0.0.1:{$port}"]);

    $started = microtime(true);
    $response = app(Gateway::class)->dispatch(['component' => 'ContentPage', 'props' => [], 'url' => '/', 'version' => '']);
    $elapsed = microtime(true) - $started;

    fclose($server);

    expect($response)->toBeNull()
        ->and($elapsed)->toBeLessThan(2.0)
        ->and(Cache::has(BoundedSsrGateway::BACK_OFF_KEY))->toBeTrue();
});

it('skips the SSR server for a moment after it timed out, then tries again', function () {
    config(['inertia.ssr.url' => 'http://ssr.test']);
    Http::fake(['ssr.test/render' => Http::response(['head' => [], 'body' => '<div id="app"></div>'])]);
    Cache::put(BoundedSsrGateway::BACK_OFF_KEY, true, 10);

    expect(app(Gateway::class)->dispatch(['component' => 'ContentPage', 'props' => [], 'url' => '/', 'version' => '']))->toBeNull()
        ->and(BoundedSsrGateway::fellBack(request()))->toBeTrue();
    Http::assertNothingSent();

    Cache::forget(BoundedSsrGateway::BACK_OFF_KEY);
    request()->attributes->remove('inertia.ssr_fell_back');

    expect(app(Gateway::class)->dispatch(['component' => 'ContentPage', 'props' => [], 'url' => '/', 'version' => '']))->not->toBeNull()
        ->and(BoundedSsrGateway::fellBack(request()))->toBeFalse();
    Http::assertSentCount(1);
});

it('still renders through a healthy SSR server', function () {
    config(['inertia.ssr.url' => 'http://ssr.test']);
    Http::fake(['ssr.test/render' => Http::response(['head' => ['<title>x</title>'], 'body' => '<div id="app">ok</div>'])]);

    $response = app(Gateway::class)->dispatch(['component' => 'ContentPage', 'props' => [], 'url' => '/', 'version' => '']);

    expect($response->head)->toBe('<title>x</title>')
        ->and($response->body)->toBe('<div id="app">ok</div>');
});
