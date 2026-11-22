<?php

use App\Listeners\RestoreRequestTimeLimit;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Octane\Events\RequestReceived;

/*
| Guards the FrankenPHP worker-mode wiring (Laravel Octane). In production the
| app boots once per worker instead of on every request; start-container.sh
| fills the two Caddyfile placeholders unless OCTANE_WORKERS=false, which
| falls back to classic per-request mode without a code change.
|
| Verified against a real FrankenPHP 1.13.3 container in both modes.
*/

function octaneRepoFile(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

it('lets the env switch the Caddyfile between worker and classic mode', function () {
    $caddy = octaneRepoFile('Caddyfile');

    expect($caddy)->toMatch('/frankenphp \{[^}]*\{\$FRANKENPHP_WORKER\}/s')
        ->and($caddy)->toContain('index {$PHP_ENTRY:index.php}')
        ->and($caddy)->toContain('try_files {path} {$PHP_ENTRY:index.php}');
});

it('starts in worker mode by default and can be switched off', function () {
    $start = octaneRepoFile('start-container.sh');

    expect($start)->toContain('"${OCTANE_WORKERS:-true}" != "false"')
        ->and($start)->toContain('export FRANKENPHP_WORKER="worker {')
        ->and($start)->toContain('file /app/public/frankenphp-worker.php')
        ->and($start)->toContain('max_threads ${OCTANE_WORKER_MAX_THREADS:-$OCTANE_WORKER_COUNT}')
        ->and($start)->toContain('env MAX_REQUESTS ${OCTANE_MAX_REQUESTS:-250}')
        ->and($start)->toContain('export PHP_ENTRY="frankenphp-worker.php"')
        ->and($start)->toContain('export FRANKENPHP_WORKER=""')
        ->and($start)->toContain('export PHP_ENTRY="index.php"');

    // The exports must happen before supervisord, which hands its env to FrankenPHP.
    expect(strpos($start, 'export PHP_ENTRY'))->toBeLessThan(strpos($start, 'exec supervisord'));
});

it('ships the Octane FrankenPHP worker entry point', function () {
    expect(file_exists(public_path('frankenphp-worker.php')))->toBeTrue()
        ->and(octaneRepoFile('public/frankenphp-worker.php'))->toContain('vendor/laravel/octane/bin/frankenphp-worker.php')
        ->and(config('octane.server'))->toBe('frankenphp');
});

it('re-arms the php.ini time limit at the start of every worker request', function () {
    expect(config('octane.listeners.'.RequestReceived::class))->toContain(RestoreRequestTimeLimit::class);
});

it('keeps the event stream out of the Caddy encoder', function () {
    // SSE frames must reach the client one by one; a compressing encoder
    // would hold them in its buffer.
    expect(octaneRepoFile('Caddyfile'))->not->toMatch('/header Content-Type text\/event-stream/')
        ->and(octaneRepoFile('php.ini'))->toContain('output_buffering = Off');
});

/**
 * The Caddyfile's @classic_slow pattern: GET paths served by classic threads.
 */
function octaneClassicGetPattern(): string
{
    preg_match('/@classic_slow path_regexp classic_slow (\S+)/', octaneRepoFile('Caddyfile'), $match);

    return '#'.$match[1].'#';
}

it('sends every write and every slow GET to the classic threads', function () {
    $caddy = octaneRepoFile('Caddyfile');

    expect($caddy)->toContain('@classic_writes not method GET HEAD')
        ->and($caddy)->toContain('rewrite @classic_writes /index.php')
        ->and($caddy)->toContain('rewrite @classic_slow /index.php');

    foreach (['/bahth', '/mstnd/12', '/_og-image', '/_og-image/adwat/x.png', '/ai/chat/turns/abc/stream', '/manage/assistant/turns/abc/stream'] as $path) {
        expect(preg_match(octaneClassicGetPattern(), $path))->toBe(1, "{$path} should run on a classic thread");
    }

    foreach (['/', '/almkrrat', '/adwat/hasbh-almadl', '/manage', '/manage/assistant', '/ai/chat/abc', '/csrf-cookie', '/bahthx', '/up'] as $path) {
        expect(preg_match(octaneClassicGetPattern(), $path))->toBe(0, "{$path} should run on an Octane worker");
    }
});

it('never serves a streamed response from an Octane worker', function () {
    // A stream holds its thread until it ends; the worker pool is small and
    // fixed. POST streams are covered by @classic_writes, GET ones must match
    // @classic_slow.
    $streamed = collect(Route::getRoutes()->getRoutes())
        ->filter(function (RoutingRoute $route): bool {
            [$class, $method] = array_pad(explode('@', $route->getActionName()), 2, '__invoke');

            if ($class === 'Closure' || ! class_exists($class) || ! method_exists($class, $method)) {
                return false;
            }

            return str_contains((string) (new ReflectionMethod($class, $method))->getReturnType(), 'StreamedResponse');
        });

    expect($streamed->map->uri()->all())->toContain('ai/chat', 'ai/chat/turns/{turn}/stream', 'manage/assistant/turns/{turn}/stream', 'mstnd/{document}');

    foreach ($streamed as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $path = '/'.ltrim((string) preg_replace('/\{[^}]+\}/', 'abc-123', $route->uri()), '/');

        expect(preg_match(octaneClassicGetPattern(), $path))->toBe(1, "{$path} streams but would run on an Octane worker");
    }
});
