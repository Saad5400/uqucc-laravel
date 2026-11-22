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
