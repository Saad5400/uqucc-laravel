<?php

use Database\Factories\PageFactory;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/*
| Server-side rendering, end to end: the BUILT SSR bundle (npm run build:ssr)
| runs as a private Node server on a free port, Laravel's Inertia gateway
| posts a real page to it, and the HTML that comes back must carry the page
| itself. A Vue / Inertia pairing that breaks SSR still answers 200 — with a
| body of literally "[object Promise]" (Vue >= 3.5.44 with @inertiajs/vue3
| 2.x) — so only looking at the markup catches it. Without SSR the page has
| no content for crawlers or for visitors without JavaScript.
*/

beforeEach(function () {
    $bundle = base_path('bootstrap/ssr/ssr.js');

    expect(file_exists($bundle))->toBeTrue('The SSR bundle is missing — run `npm run build:ssr` first.');

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $this->ssrPort = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $node = (new ExecutableFinder)->find('node') ?? 'node';
    $this->ssr = new Process([$node, $bundle], base_path(), ['INERTIA_SSR_PORT' => (string) $this->ssrPort], null, 60);
    $this->ssr->start();

    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline && @file_get_contents("http://127.0.0.1:{$this->ssrPort}/health") === false) {
        usleep(100_000);
    }

    config([
        'inertia.ssr.enabled' => true,
        'inertia.ssr.url' => "http://127.0.0.1:{$this->ssrPort}",
    ]);
});

afterEach(function () {
    $this->ssr?->stop(1);
});

it('server-renders a content page with its heading, not "[object Promise]"', function () {
    PageFactory::new()->create(['slug' => '/almqrrat', 'title' => 'المقررات الدراسية']);

    $html = $this->get('/almqrrat')->assertOk()->getContent();

    expect($html)->not->toContain('[object Promise]')
        ->and($html)->toMatch('/<h1[^>]*>\s*المقررات الدراسية\s*<\/h1>/u');
});

it('does not keep a client-rendered fallback in the full-page cache once SSR is back', function () {
    PageFactory::new()->create(['slug' => '/almqrrat', 'title' => 'المقررات الدراسية']);
    PageFactory::new()->create(['slug' => '/warm-up', 'title' => 'تسخين']);
    $serverRendered = '/<h1[^>]*>\s*المقررات الدراسية\s*<\/h1>/u';
    $visit = fn (string $uri = '/almqrrat') => $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get($uri);

    // A first render compiles the page component; keep it out of the deadline.
    $visit('/warm-up')->assertOk();
    config(['inertia.ssr.timeout' => 1.0]);

    // SSR freezes: the page falls back to the client-rendered shell, and
    // neither that render nor the next (inside the skip window) is cached.
    $this->ssr->signal(SIGSTOP);

    $started = microtime(true);
    $shell = $visit()->assertOk();
    expect(microtime(true) - $started)->toBeLessThan(3.0)
        ->and($shell->getContent())->not->toMatch($serverRendered);

    $visit()->assertOk()->assertHeaderMissing('X-Cache');

    // SSR recovers; once the skip window is over the page renders on the
    // server again, and that render is what gets cached.
    $this->ssr->signal(SIGCONT);
    $this->travel(11)->seconds();

    expect($visit()->assertOk()->assertHeaderMissing('X-Cache')->getContent())->toMatch($serverRendered);

    $hit = $visit()->assertOk()->assertHeader('X-Cache', 'HIT');
    expect($hit->getContent())->toMatch($serverRendered);
});
